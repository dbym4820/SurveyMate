/**
 * 認証モジュール
 * セッションベース認証 + bcrypt
 */
import type { Request, Response, NextFunction, AuthenticatedUser } from '../types/index.js';
/**
 * パスワードをハッシュ化
 */
export declare function hashPassword(password: string): Promise<string>;
/**
 * パスワードを検証
 */
export declare function verifyPassword(password: string, hash: string): Promise<boolean>;
/**
 * ログイン処理
 */
export declare function login(username: string, password: string): Promise<{
    user: {
        id: number;
        username: string;
        email: string | null;
        isAdmin: boolean;
    };
    session: {
        sessionId: string;
        expiresAt: Date;
    };
} | null>;
/**
 * ログアウト処理
 */
export declare function logout(sessionId: string): Promise<void>;
/**
 * セッション検証
 */
export declare function validateSession(sessionId: string): Promise<AuthenticatedUser | null>;
/**
 * 認証ミドルウェア
 */
export declare function authMiddleware(req: Request, res: Response, next: NextFunction): Promise<void>;
/**
 * オプショナル認証ミドルウェア（認証なしでもアクセス可）
 */
export declare function optionalAuth(req: Request, _res: Response, next: NextFunction): Promise<void>;
/**
 * 管理者権限チェックミドルウェア
 */
export declare function adminOnly(req: Request, res: Response, next: NextFunction): void;
/**
 * ユーザー登録（管理者のみ）
 */
export declare function registerUser(username: string, password: string, email: string | null, isAdmin?: boolean): Promise<{
    id: number;
    username: string;
    email: string | null;
    isAdmin: boolean;
}>;
declare const _default: {
    hashPassword: typeof hashPassword;
    verifyPassword: typeof verifyPassword;
    login: typeof login;
    logout: typeof logout;
    validateSession: typeof validateSession;
    authMiddleware: typeof authMiddleware;
    optionalAuth: typeof optionalAuth;
    adminOnly: typeof adminOnly;
    registerUser: typeof registerUser;
};
export default _default;
//# sourceMappingURL=auth.d.ts.map