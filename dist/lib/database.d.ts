/**
 * データベース接続モジュール
 * MySQL2 + コネクションプール
 */
import mysql, { PoolConnection, RowDataPacket, ResultSetHeader } from 'mysql2/promise';
import type { User, Session, Journal, Paper, Summary, FetchLog, PaperInput, SummaryInput, FetchLogInput, PapersListParams } from '../types/index.js';
/**
 * 接続テスト
 */
export declare function testConnection(): Promise<boolean>;
/**
 * クエリ実行
 */
export declare function query<T extends RowDataPacket[] | ResultSetHeader>(sql: string, params?: unknown[]): Promise<T>;
/**
 * トランザクション実行
 */
export declare function transaction<T>(callback: (connection: PoolConnection) => Promise<T>): Promise<T>;
/**
 * プール終了
 */
export declare function end(): Promise<void>;
export declare const papers: {
    /**
     * 論文を挿入または更新（重複チェック付き）
     */
    upsert(paper: PaperInput): Promise<ResultSetHeader>;
    /**
     * 論文一覧を取得
     */
    findAll(params?: PapersListParams): Promise<Paper[]>;
    /**
     * 論文を1件取得
     */
    findById(id: number | string): Promise<Paper | null>;
    /**
     * 論文数を取得
     */
    count(params?: Omit<PapersListParams, "limit" | "offset">): Promise<number>;
};
export declare const journals: {
    /**
     * 全論文誌を取得
     */
    findAll(activeOnly?: boolean): Promise<Journal[]>;
    /**
     * 論文誌を1件取得
     */
    findById(id: string): Promise<Journal | null>;
    /**
     * 最終取得日時を更新
     */
    updateLastFetched(id: string): Promise<ResultSetHeader>;
};
export declare const summaries: {
    /**
     * 要約を作成
     */
    create(summary: SummaryInput): Promise<Summary & {
        id: number;
    }>;
    /**
     * 論文IDで要約を取得
     */
    findByPaperId(paperId: number | string): Promise<Summary[]>;
};
export declare const users: {
    /**
     * ユーザー名で検索
     */
    findByUsername(username: string): Promise<User | null>;
    /**
     * IDで検索
     */
    findById(id: number): Promise<Omit<User, "password_hash"> | null>;
    /**
     * ユーザー作成
     */
    create(user: {
        username: string;
        password_hash: string;
        email: string | null;
        is_admin?: boolean;
    }): Promise<{
        id: number;
        username: string;
        email: string | null;
        is_admin: boolean;
    }>;
    /**
     * 最終ログイン更新
     */
    updateLastLogin(id: number): Promise<ResultSetHeader>;
};
export declare const sessions: {
    /**
     * セッション作成
     */
    create(sessionId: string, userId: number, expiresAt: Date): Promise<ResultSetHeader>;
    /**
     * セッション取得
     */
    findById(sessionId: string): Promise<Session | null>;
    /**
     * セッション削除
     */
    delete(sessionId: string): Promise<ResultSetHeader>;
    /**
     * ユーザーの全セッション削除
     */
    deleteByUserId(userId: number): Promise<ResultSetHeader>;
};
export declare const fetchLogs: {
    /**
     * ログを作成
     */
    create(log: FetchLogInput): Promise<FetchLog & {
        id: number;
    }>;
    /**
     * 最新ログを取得
     */
    findRecent(limit?: number): Promise<FetchLog[]>;
};
declare const _default: {
    pool: mysql.Pool;
    query: typeof query;
    transaction: typeof transaction;
    testConnection: typeof testConnection;
    end: typeof end;
    papers: {
        /**
         * 論文を挿入または更新（重複チェック付き）
         */
        upsert(paper: PaperInput): Promise<ResultSetHeader>;
        /**
         * 論文一覧を取得
         */
        findAll(params?: PapersListParams): Promise<Paper[]>;
        /**
         * 論文を1件取得
         */
        findById(id: number | string): Promise<Paper | null>;
        /**
         * 論文数を取得
         */
        count(params?: Omit<PapersListParams, "limit" | "offset">): Promise<number>;
    };
    journals: {
        /**
         * 全論文誌を取得
         */
        findAll(activeOnly?: boolean): Promise<Journal[]>;
        /**
         * 論文誌を1件取得
         */
        findById(id: string): Promise<Journal | null>;
        /**
         * 最終取得日時を更新
         */
        updateLastFetched(id: string): Promise<ResultSetHeader>;
    };
    summaries: {
        /**
         * 要約を作成
         */
        create(summary: SummaryInput): Promise<Summary & {
            id: number;
        }>;
        /**
         * 論文IDで要約を取得
         */
        findByPaperId(paperId: number | string): Promise<Summary[]>;
    };
    users: {
        /**
         * ユーザー名で検索
         */
        findByUsername(username: string): Promise<User | null>;
        /**
         * IDで検索
         */
        findById(id: number): Promise<Omit<User, "password_hash"> | null>;
        /**
         * ユーザー作成
         */
        create(user: {
            username: string;
            password_hash: string;
            email: string | null;
            is_admin?: boolean;
        }): Promise<{
            id: number;
            username: string;
            email: string | null;
            is_admin: boolean;
        }>;
        /**
         * 最終ログイン更新
         */
        updateLastLogin(id: number): Promise<ResultSetHeader>;
    };
    sessions: {
        /**
         * セッション作成
         */
        create(sessionId: string, userId: number, expiresAt: Date): Promise<ResultSetHeader>;
        /**
         * セッション取得
         */
        findById(sessionId: string): Promise<Session | null>;
        /**
         * セッション削除
         */
        delete(sessionId: string): Promise<ResultSetHeader>;
        /**
         * ユーザーの全セッション削除
         */
        deleteByUserId(userId: number): Promise<ResultSetHeader>;
    };
    fetchLogs: {
        /**
         * ログを作成
         */
        create(log: FetchLogInput): Promise<FetchLog & {
            id: number;
        }>;
        /**
         * 最新ログを取得
         */
        findRecent(limit?: number): Promise<FetchLog[]>;
    };
};
export default _default;
//# sourceMappingURL=database.d.ts.map