/**
 * データベース接続モジュール
 * MySQL2 + コネクションプール
 */
import mysql from 'mysql2/promise';
import logger from './logger.js';
// コネクションプール作成
const pool = mysql.createPool({
    host: process.env.DB_HOST || 'localhost',
    port: parseInt(process.env.DB_PORT || '3306'),
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'academic_papers',
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0,
    charset: 'utf8mb4',
    timezone: '+09:00',
});
/**
 * 接続テスト
 */
export async function testConnection() {
    const connection = await pool.getConnection();
    try {
        await connection.query('SELECT 1');
        return true;
    }
    finally {
        connection.release();
    }
}
/**
 * クエリ実行
 */
export async function query(sql, params = []) {
    try {
        const [rows] = await pool.execute(sql, params);
        return rows;
    }
    catch (error) {
        logger.error('Database query error:', { sql, error: error.message });
        throw error;
    }
}
/**
 * トランザクション実行
 */
export async function transaction(callback) {
    const connection = await pool.getConnection();
    try {
        await connection.beginTransaction();
        const result = await callback(connection);
        await connection.commit();
        return result;
    }
    catch (error) {
        await connection.rollback();
        throw error;
    }
    finally {
        connection.release();
    }
}
/**
 * プール終了
 */
export async function end() {
    await pool.end();
}
// =====================================================
// 論文関連クエリ
// =====================================================
export const papers = {
    /**
     * 論文を挿入または更新（重複チェック付き）
     */
    async upsert(paper) {
        const sql = `
      INSERT INTO papers (journal_id, title, authors, abstract, url, doi, published_date, external_id)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        abstract = VALUES(abstract),
        url = VALUES(url),
        doi = VALUES(doi),
        updated_at = CURRENT_TIMESTAMP
    `;
        const params = [
            paper.journal_id,
            paper.title,
            JSON.stringify(paper.authors || []),
            paper.abstract || null,
            paper.url || null,
            paper.doi || null,
            paper.published_date || null,
            paper.external_id || null,
        ];
        return query(sql, params);
    },
    /**
     * 論文一覧を取得
     */
    async findAll(params = {}) {
        const { journalIds, dateFrom, dateTo, limit = 100, offset = 0, search } = params;
        let sql = `
      SELECT
        p.*,
        j.name AS journal_name,
        j.full_name AS journal_full_name,
        j.color AS journal_color,
        j.category,
        (SELECT COUNT(*) FROM summaries s WHERE s.paper_id = p.id) AS has_summary
      FROM papers p
      JOIN journals j ON p.journal_id = j.id
      WHERE 1=1
    `;
        const queryParams = [];
        if (journalIds && journalIds.length > 0) {
            sql += ` AND p.journal_id IN (${journalIds.map(() => '?').join(',')})`;
            queryParams.push(...journalIds);
        }
        if (dateFrom) {
            sql += ` AND p.published_date >= ?`;
            queryParams.push(dateFrom);
        }
        if (dateTo) {
            sql += ` AND p.published_date <= ?`;
            queryParams.push(dateTo);
        }
        if (search) {
            sql += ` AND (p.title LIKE ? OR p.abstract LIKE ?)`;
            const searchTerm = `%${search}%`;
            queryParams.push(searchTerm, searchTerm);
        }
        sql += ` ORDER BY p.published_date DESC, p.fetched_at DESC`;
        sql += ` LIMIT ? OFFSET ?`;
        queryParams.push(limit, offset);
        const rows = await query(sql, queryParams);
        return rows.map(row => ({
            ...row,
            authors: typeof row.authors === 'string' ? JSON.parse(row.authors) : row.authors,
        }));
    },
    /**
     * 論文を1件取得
     */
    async findById(id) {
        const sql = `
      SELECT
        p.*,
        j.name AS journal_name,
        j.full_name AS journal_full_name,
        j.color AS journal_color,
        j.category
      FROM papers p
      JOIN journals j ON p.journal_id = j.id
      WHERE p.id = ?
    `;
        const rows = await query(sql, [id]);
        if (rows.length === 0)
            return null;
        const row = rows[0];
        return {
            ...row,
            authors: typeof row.authors === 'string' ? JSON.parse(row.authors) : row.authors,
        };
    },
    /**
     * 論文数を取得
     */
    async count(params = {}) {
        const { journalIds, dateFrom, dateTo, search } = params;
        let sql = `SELECT COUNT(*) AS count FROM papers p WHERE 1=1`;
        const queryParams = [];
        if (journalIds && journalIds.length > 0) {
            sql += ` AND p.journal_id IN (${journalIds.map(() => '?').join(',')})`;
            queryParams.push(...journalIds);
        }
        if (dateFrom) {
            sql += ` AND p.published_date >= ?`;
            queryParams.push(dateFrom);
        }
        if (dateTo) {
            sql += ` AND p.published_date <= ?`;
            queryParams.push(dateTo);
        }
        if (search) {
            sql += ` AND (p.title LIKE ? OR p.abstract LIKE ?)`;
            const searchTerm = `%${search}%`;
            queryParams.push(searchTerm, searchTerm);
        }
        const rows = await query(sql, queryParams);
        return rows[0].count;
    },
};
// =====================================================
// 論文誌関連クエリ
// =====================================================
export const journals = {
    /**
     * 全論文誌を取得
     */
    async findAll(activeOnly = true) {
        let sql = `
      SELECT j.*,
        (SELECT COUNT(*) FROM papers p WHERE p.journal_id = j.id) AS paper_count
      FROM journals j
    `;
        if (activeOnly) {
            sql += ` WHERE j.is_active = TRUE`;
        }
        sql += ` ORDER BY j.category, j.name`;
        return query(sql);
    },
    /**
     * 論文誌を1件取得
     */
    async findById(id) {
        const sql = `SELECT * FROM journals WHERE id = ?`;
        const rows = await query(sql, [id]);
        return rows[0] || null;
    },
    /**
     * 最終取得日時を更新
     */
    async updateLastFetched(id) {
        const sql = `UPDATE journals SET last_fetched_at = CURRENT_TIMESTAMP WHERE id = ?`;
        return query(sql, [id]);
    },
};
// =====================================================
// 要約関連クエリ
// =====================================================
export const summaries = {
    /**
     * 要約を作成
     */
    async create(summary) {
        const sql = `
      INSERT INTO summaries (paper_id, ai_provider, ai_model, summary_text, purpose, methodology, findings, implications, tokens_used, generation_time_ms)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `;
        const params = [
            summary.paper_id,
            summary.ai_provider,
            summary.ai_model,
            summary.summary_text,
            summary.purpose || null,
            summary.methodology || null,
            summary.findings || null,
            summary.implications || null,
            summary.tokens_used || null,
            summary.generation_time_ms || null,
        ];
        const result = await query(sql, params);
        return { id: result.insertId, ...summary };
    },
    /**
     * 論文IDで要約を取得
     */
    async findByPaperId(paperId) {
        const sql = `SELECT * FROM summaries WHERE paper_id = ? ORDER BY created_at DESC`;
        return query(sql, [paperId]);
    },
};
// =====================================================
// ユーザー関連クエリ
// =====================================================
export const users = {
    /**
     * ユーザー名で検索
     */
    async findByUsername(username) {
        const sql = `SELECT * FROM users WHERE username = ? AND is_active = TRUE`;
        const rows = await query(sql, [username]);
        return rows[0] || null;
    },
    /**
     * IDで検索
     */
    async findById(id) {
        const sql = `SELECT id, username, email, is_admin, created_at FROM users WHERE id = ? AND is_active = TRUE`;
        const rows = await query(sql, [id]);
        return rows[0] || null;
    },
    /**
     * ユーザー作成
     */
    async create(user) {
        const sql = `INSERT INTO users (username, password_hash, email, is_admin) VALUES (?, ?, ?, ?)`;
        const result = await query(sql, [
            user.username,
            user.password_hash,
            user.email,
            user.is_admin || false,
        ]);
        return {
            id: result.insertId,
            username: user.username,
            email: user.email,
            is_admin: user.is_admin || false,
        };
    },
    /**
     * 最終ログイン更新
     */
    async updateLastLogin(id) {
        const sql = `UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?`;
        return query(sql, [id]);
    },
};
// =====================================================
// セッション関連クエリ
// =====================================================
export const sessions = {
    /**
     * セッション作成
     */
    async create(sessionId, userId, expiresAt) {
        const sql = `INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, ?)`;
        return query(sql, [sessionId, userId, expiresAt]);
    },
    /**
     * セッション取得
     */
    async findById(sessionId) {
        const sql = `
      SELECT s.*, u.username, u.is_admin
      FROM sessions s
      JOIN users u ON s.user_id = u.id
      WHERE s.id = ? AND s.expires_at > NOW() AND u.is_active = TRUE
    `;
        const rows = await query(sql, [sessionId]);
        return rows[0] || null;
    },
    /**
     * セッション削除
     */
    async delete(sessionId) {
        const sql = `DELETE FROM sessions WHERE id = ?`;
        return query(sql, [sessionId]);
    },
    /**
     * ユーザーの全セッション削除
     */
    async deleteByUserId(userId) {
        const sql = `DELETE FROM sessions WHERE user_id = ?`;
        return query(sql, [userId]);
    },
};
// =====================================================
// 取得ログ関連クエリ
// =====================================================
export const fetchLogs = {
    /**
     * ログを作成
     */
    async create(log) {
        const sql = `
      INSERT INTO fetch_logs (journal_id, status, papers_fetched, new_papers, error_message, execution_time_ms)
      VALUES (?, ?, ?, ?, ?, ?)
    `;
        const result = await query(sql, [
            log.journal_id,
            log.status,
            log.papers_fetched || 0,
            log.new_papers || 0,
            log.error_message || null,
            log.execution_time_ms || null,
        ]);
        return { id: result.insertId, ...log };
    },
    /**
     * 最新ログを取得
     */
    async findRecent(limit = 50) {
        const sql = `
      SELECT fl.*, j.name AS journal_name
      FROM fetch_logs fl
      LEFT JOIN journals j ON fl.journal_id = j.id
      ORDER BY fl.created_at DESC
      LIMIT ?
    `;
        return query(sql, [limit]);
    },
};
export default {
    pool,
    query,
    transaction,
    testConnection,
    end,
    papers,
    journals,
    summaries,
    users,
    sessions,
    fetchLogs,
};
//# sourceMappingURL=database.js.map