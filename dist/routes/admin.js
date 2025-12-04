/**
 * 管理者ルーター
 * /api/admin
 */
import { Router } from 'express';
import Parser from 'rss-parser';
import { adminOnly } from '../lib/auth.js';
import * as db from '../lib/database.js';
import * as scheduler from '../lib/scheduler.js';
import logger from '../lib/logger.js';
const router = Router();
// 注意: 論文誌管理は認証済みユーザー全員がアクセス可能
// 管理者専用ルートは個別に adminOnly を適用
/**
 * GET /api/admin/scheduler/status
 * スケジューラーの状態を取得
 */
router.get('/scheduler/status', adminOnly, async (_req, res) => {
    try {
        const status = scheduler.getStatus();
        res.json({
            success: true,
            scheduler: status,
        });
    }
    catch (error) {
        logger.error('Get scheduler status error:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});
/**
 * POST /api/admin/scheduler/run
 * 手動でRSS取得を実行
 */
router.post('/scheduler/run', adminOnly, async (req, res) => {
    try {
        const { journalId } = req.body;
        let result;
        if (journalId) {
            // 特定の論文誌のみ
            result = await scheduler.fetchJournal(journalId);
        }
        else {
            // 全論文誌
            result = await scheduler.runNow();
        }
        res.json({
            success: true,
            result,
        });
    }
    catch (error) {
        logger.error('Manual fetch error:', error);
        res.status(500).json({ error: error.message || 'Fetch failed' });
    }
});
/**
 * GET /api/admin/logs
 * 取得ログを取得
 */
router.get('/logs', adminOnly, async (req, res) => {
    try {
        const { limit = '50' } = req.query;
        const logs = await db.fetchLogs.findRecent(parseInt(limit));
        res.json({
            success: true,
            logs,
        });
    }
    catch (error) {
        logger.error('Get logs error:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});
/**
 * POST /api/admin/journals
 * 論文誌を追加（認証済みユーザー全員）
 */
router.post('/journals', async (req, res) => {
    try {
        const { id, name, fullName, publisher, rssUrl, category, color } = req.body;
        if (!id || !name || !fullName || !publisher || !rssUrl) {
            res.status(400).json({ error: 'Missing required fields: id, name, fullName, publisher, rssUrl' });
            return;
        }
        // IDのバリデーション（英数字とハイフンのみ）
        if (!/^[a-z0-9-]+$/.test(id)) {
            res.status(400).json({ error: 'ID must contain only lowercase letters, numbers, and hyphens' });
            return;
        }
        const sql = `
      INSERT INTO journals (id, name, full_name, publisher, rss_url, category, color)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    `;
        await db.query(sql, [id, name, fullName, publisher, rssUrl, category || 'Other', color || 'bg-gray-500']);
        logger.info('Journal added', { id, name, user: req.user.username });
        res.status(201).json({
            success: true,
            message: 'Journal added successfully',
            journal: { id, name, fullName, publisher, rssUrl, category, color },
        });
    }
    catch (error) {
        if (error.code === 'ER_DUP_ENTRY') {
            res.status(409).json({ error: 'Journal ID already exists' });
            return;
        }
        logger.error('Add journal error:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});
/**
 * POST /api/admin/journals/test-rss
 * RSSフィードをテスト（認証済みユーザー全員）
 */
router.post('/journals/test-rss', async (req, res) => {
    try {
        const { rssUrl } = req.body;
        if (!rssUrl) {
            res.status(400).json({ error: 'RSS URL is required' });
            return;
        }
        const parser = new Parser({ timeout: 15000 });
        const feed = await parser.parseURL(rssUrl);
        res.json({
            success: true,
            feedTitle: feed.title,
            itemCount: feed.items.length,
            sampleItems: feed.items.slice(0, 3).map(item => ({
                title: item.title,
                pubDate: item.pubDate,
                author: item.creator || item.author,
            })),
        });
    }
    catch (error) {
        logger.error('RSS test error:', error);
        res.status(400).json({
            success: false,
            error: 'Failed to fetch RSS feed: ' + error.message,
        });
    }
});
/**
 * GET /api/admin/journals/:id/fetch
 * 特定の論文誌のRSSを即座に取得（認証済みユーザー全員）
 */
router.get('/journals/:id/fetch', async (req, res) => {
    try {
        const { id } = req.params;
        const result = await scheduler.fetchJournal(id);
        res.json({
            success: true,
            result,
        });
    }
    catch (error) {
        logger.error('Fetch journal error:', error);
        res.status(500).json({ error: error.message || 'Fetch failed' });
    }
});
/**
 * PUT /api/admin/journals/:id
 * 論文誌を更新（認証済みユーザー全員）
 */
router.put('/journals/:id', async (req, res) => {
    try {
        const { id } = req.params;
        const { name, fullName, publisher, rssUrl, category, color, isActive } = req.body;
        const journal = await db.journals.findById(id);
        if (!journal) {
            res.status(404).json({ error: 'Journal not found' });
            return;
        }
        const sql = `
      UPDATE journals SET
        name = COALESCE(?, name),
        full_name = COALESCE(?, full_name),
        publisher = COALESCE(?, publisher),
        rss_url = COALESCE(?, rss_url),
        category = COALESCE(?, category),
        color = COALESCE(?, color),
        is_active = COALESCE(?, is_active)
      WHERE id = ?
    `;
        await db.query(sql, [name, fullName, publisher, rssUrl, category, color, isActive, id]);
        logger.info('Journal updated', { id, user: req.user.username });
        // 更新後のデータを取得して返す
        const updatedJournal = await db.journals.findById(id);
        res.json({
            success: true,
            message: 'Journal updated successfully',
            journal: updatedJournal,
        });
    }
    catch (error) {
        logger.error('Update journal error:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});
/**
 * DELETE /api/admin/journals/:id
 * 論文誌を削除（論理削除）（認証済みユーザー全員）
 */
router.delete('/journals/:id', async (req, res) => {
    try {
        const { id } = req.params;
        const { permanent } = req.query;
        const journal = await db.journals.findById(id);
        if (!journal) {
            res.status(404).json({ error: 'Journal not found' });
            return;
        }
        if (permanent === 'true') {
            // 完全削除（関連する論文も削除される - CASCADE）
            const sql = `DELETE FROM journals WHERE id = ?`;
            await db.query(sql, [id]);
            logger.info('Journal permanently deleted', { id, user: req.user.username });
            res.json({
                success: true,
                message: 'Journal permanently deleted',
            });
        }
        else {
            // 論理削除（無効化）
            const sql = `UPDATE journals SET is_active = FALSE WHERE id = ?`;
            await db.query(sql, [id]);
            logger.info('Journal deactivated', { id, user: req.user.username });
            res.json({
                success: true,
                message: 'Journal deactivated',
            });
        }
    }
    catch (error) {
        logger.error('Delete journal error:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});
/**
 * POST /api/admin/journals/:id/activate
 * 論文誌を再有効化（認証済みユーザー全員）
 */
router.post('/journals/:id/activate', async (req, res) => {
    try {
        const { id } = req.params;
        const sql = `UPDATE journals SET is_active = TRUE WHERE id = ?`;
        const result = await db.query(sql, [id]);
        if (result.affectedRows === 0) {
            res.status(404).json({ error: 'Journal not found' });
            return;
        }
        logger.info('Journal activated', { id, user: req.user.username });
        res.json({
            success: true,
            message: 'Journal activated',
        });
    }
    catch (error) {
        logger.error('Activate journal error:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});
/**
 * GET /api/admin/users
 * ユーザー一覧を取得（管理者のみ）
 */
router.get('/users', adminOnly, async (_req, res) => {
    try {
        const sql = `
      SELECT id, username, email, is_admin, is_active, last_login_at, created_at
      FROM users
      ORDER BY created_at DESC
    `;
        const users = await db.query(sql);
        res.json({
            success: true,
            users,
        });
    }
    catch (error) {
        logger.error('Get users error:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});
export default router;
//# sourceMappingURL=admin.js.map