/**
 * 認証ルーター
 * /api/auth
 */

import { Router } from 'express';
import * as auth from '../lib/auth.js';
import logger from '../lib/logger.js';
import type { Request, Response } from '../types/index.js';

const router = Router();

/**
 * POST /api/auth/login
 * ログイン
 */
router.post('/login', async (req: Request, res: Response): Promise<void> => {
  try {
    const { username, password } = req.body as { username?: string; password?: string };

    if (!username || !password) {
      res.status(400).json({ error: 'Username and password are required' });
      return;
    }

    const result = await auth.login(username, password);

    if (!result) {
      res.status(401).json({ error: 'Invalid credentials' });
      return;
    }

    // セッションCookieを設定
    res.cookie('session_id', result.session.sessionId, {
      httpOnly: true,
      secure: process.env.NODE_ENV === 'production',
      sameSite: 'lax',
      expires: result.session.expiresAt,
    });

    res.json({
      success: true,
      user: result.user,
      expiresAt: result.session.expiresAt,
    });
  } catch (error) {
    logger.error('Login error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

/**
 * POST /api/auth/logout
 * ログアウト
 */
router.post('/logout', async (req: Request, res: Response): Promise<void> => {
  try {
    const sessionId = req.cookies?.session_id as string | undefined;

    if (sessionId) {
      await auth.logout(sessionId);
    }

    res.clearCookie('session_id');
    res.json({ success: true });
  } catch (error) {
    logger.error('Logout error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

/**
 * GET /api/auth/me
 * 現在のユーザー情報を取得
 */
router.get('/me', async (req: Request, res: Response): Promise<void> => {
  try {
    const sessionId = req.cookies?.session_id as string | undefined;

    if (!sessionId) {
      res.status(401).json({ error: 'Not authenticated' });
      return;
    }

    const session = await auth.validateSession(sessionId);

    if (!session) {
      res.clearCookie('session_id');
      res.status(401).json({ error: 'Session expired' });
      return;
    }

    res.json({
      authenticated: true,
      user: {
        id: session.userId,
        username: session.username,
        isAdmin: session.isAdmin,
      },
    });
  } catch (error) {
    logger.error('Get user error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

/**
 * POST /api/auth/register
 * ユーザー登録（セルフ登録可能）
 */
router.post('/register', async (req: Request, res: Response): Promise<void> => {
  try {
    const { username, password, email } = req.body as {
      username?: string;
      password?: string;
      email?: string;
    };

    if (!username || !password) {
      res.status(400).json({ error: 'Username and password are required' });
      return;
    }

    // ユーザー名のバリデーション
    if (username.length < 3 || username.length > 50) {
      res.status(400).json({ error: 'Username must be between 3 and 50 characters' });
      return;
    }

    if (!/^[a-zA-Z0-9_-]+$/.test(username)) {
      res.status(400).json({ error: 'Username can only contain letters, numbers, underscores, and hyphens' });
      return;
    }

    // パスワードのバリデーション
    if (password.length < 8) {
      res.status(400).json({ error: 'Password must be at least 8 characters' });
      return;
    }

    // メールアドレスのバリデーション（任意）
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      res.status(400).json({ error: 'Invalid email address format' });
      return;
    }

    // セルフ登録は常に非管理者
    const user = await auth.registerUser(username, password, email || null, false);

    // 登録成功時に自動ログイン
    const loginResult = await auth.login(username, password);

    if (loginResult) {
      res.cookie('session_id', loginResult.session.sessionId, {
        httpOnly: true,
        secure: process.env.NODE_ENV === 'production',
        sameSite: 'lax',
        expires: loginResult.session.expiresAt,
      });

      res.status(201).json({
        success: true,
        user,
        expiresAt: loginResult.session.expiresAt,
      });
    } else {
      res.status(201).json({
        success: true,
        user,
      });
    }
  } catch (error) {
    if ((error as Error).message === 'Username already exists') {
      res.status(409).json({ error: (error as Error).message });
      return;
    }
    logger.error('Register error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

export default router;
