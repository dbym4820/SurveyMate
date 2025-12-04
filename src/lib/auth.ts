/**
 * 認証モジュール
 * セッションベース認証 + bcrypt
 */

import bcrypt from 'bcrypt';
import crypto from 'crypto';
import * as db from './database.js';
import logger from './logger.js';
import type { Request, Response, NextFunction, AuthenticatedUser } from '../types/index.js';

const SALT_ROUNDS = 10;
const SESSION_EXPIRES = parseInt(process.env.SESSION_EXPIRES || '86400'); // 24時間

/**
 * パスワードをハッシュ化
 */
export async function hashPassword(password: string): Promise<string> {
  return bcrypt.hash(password, SALT_ROUNDS);
}

/**
 * パスワードを検証
 */
export async function verifyPassword(password: string, hash: string): Promise<boolean> {
  return bcrypt.compare(password, hash);
}

/**
 * セッションIDを生成
 */
function generateSessionId(): string {
  return crypto.randomBytes(32).toString('hex');
}

/**
 * セッションを作成
 */
async function createSession(userId: number): Promise<{ sessionId: string; expiresAt: Date }> {
  const sessionId = generateSessionId();
  const expiresAt = new Date(Date.now() + SESSION_EXPIRES * 1000);

  await db.sessions.create(sessionId, userId, expiresAt);

  return {
    sessionId,
    expiresAt,
  };
}

/**
 * ログイン処理
 */
export async function login(
  username: string,
  password: string
): Promise<{
  user: { id: number; username: string; email: string | null; isAdmin: boolean };
  session: { sessionId: string; expiresAt: Date };
} | null> {
  const user = await db.users.findByUsername(username);

  if (!user) {
    logger.warn('Login failed: user not found', { username });
    return null;
  }

  const isValid = await verifyPassword(password, user.password_hash);

  if (!isValid) {
    logger.warn('Login failed: invalid password', { username });
    return null;
  }

  // 最終ログイン日時を更新
  await db.users.updateLastLogin(user.id);

  // セッション作成
  const session = await createSession(user.id);

  logger.info('Login successful', { username, userId: user.id });

  return {
    user: {
      id: user.id,
      username: user.username,
      email: user.email,
      isAdmin: user.is_admin,
    },
    session,
  };
}

/**
 * ログアウト処理
 */
export async function logout(sessionId: string): Promise<void> {
  await db.sessions.delete(sessionId);
  logger.info('Logout successful', { sessionId: sessionId.substring(0, 8) + '...' });
}

/**
 * セッション検証
 */
export async function validateSession(sessionId: string): Promise<AuthenticatedUser | null> {
  if (!sessionId) return null;

  const session = await db.sessions.findById(sessionId);

  if (!session) {
    return null;
  }

  return {
    userId: session.user_id,
    username: session.username!,
    isAdmin: session.is_admin!,
  };
}

/**
 * 認証ミドルウェア
 */
export async function authMiddleware(
  req: Request,
  res: Response,
  next: NextFunction
): Promise<void> {
  // Cookieからセッション取得
  const sessionId = req.cookies?.session_id as string | undefined;

  // Authorizationヘッダーからも取得可能
  const authHeader = req.headers.authorization;
  const bearerToken = authHeader?.startsWith('Bearer ')
    ? authHeader.substring(7)
    : null;

  const token = sessionId || bearerToken;

  if (!token) {
    res.status(401).json({ error: 'Authentication required' });
    return;
  }

  const session = await validateSession(token);

  if (!session) {
    res.clearCookie('session_id');
    res.status(401).json({ error: 'Invalid or expired session' });
    return;
  }

  // リクエストにユーザー情報を付与
  req.user = session;
  next();
}

/**
 * オプショナル認証ミドルウェア（認証なしでもアクセス可）
 */
export async function optionalAuth(
  req: Request,
  _res: Response,
  next: NextFunction
): Promise<void> {
  const sessionId = req.cookies?.session_id as string | undefined;

  if (sessionId) {
    const session = await validateSession(sessionId);
    if (session) {
      req.user = session;
    }
  }

  next();
}

/**
 * 管理者権限チェックミドルウェア
 */
export function adminOnly(req: Request, res: Response, next: NextFunction): void {
  if (!req.user?.isAdmin) {
    res.status(403).json({ error: 'Admin access required' });
    return;
  }
  next();
}

/**
 * ユーザー登録（管理者のみ）
 */
export async function registerUser(
  username: string,
  password: string,
  email: string | null,
  isAdmin = false
): Promise<{ id: number; username: string; email: string | null; isAdmin: boolean }> {
  const existingUser = await db.users.findByUsername(username);

  if (existingUser) {
    throw new Error('Username already exists');
  }

  const passwordHash = await hashPassword(password);

  const user = await db.users.create({
    username,
    password_hash: passwordHash,
    email,
    is_admin: isAdmin,
  });

  logger.info('User registered', { username, userId: user.id });

  return {
    id: user.id,
    username,
    email,
    isAdmin,
  };
}

export default {
  hashPassword,
  verifyPassword,
  login,
  logout,
  validateSession,
  authMiddleware,
  optionalAuth,
  adminOnly,
  registerUser,
};
