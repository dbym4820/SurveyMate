/**
 * 論文ルーター
 * /api/papers
 */
import { Router } from 'express';
import * as db from '../lib/database.js';
import logger from '../lib/logger.js';
const router = Router();
/**
 * GET /api/papers
 * 論文一覧を取得
 */
router.get('/', async (req, res) => {
    try {
        const { journals, dateFrom, dateTo, search, limit = '50', offset = '0', } = req.query;
        // 論文誌IDの配列化
        const journalIds = journals ? journals.split(',') : undefined;
        const papers = await db.papers.findAll({
            journalIds,
            dateFrom,
            dateTo,
            search,
            limit: parseInt(limit),
            offset: parseInt(offset),
        });
        const total = await db.papers.count({
            journalIds,
            dateFrom,
            dateTo,
            search,
        });
        res.json({
            success: true,
            papers,
            pagination: {
                total,
                limit: parseInt(limit),
                offset: parseInt(offset),
                hasMore: parseInt(offset) + papers.length < total,
            },
        });
    }
    catch (error) {
        logger.error('Get papers error:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});
/**
 * GET /api/papers/stats
 * 論文統計を取得
 */
router.get('/stats', async (req, res) => {
    try {
        const { dateFrom, dateTo } = req.query;
        // 論文誌ごとの統計
        const journals = await db.journals.findAll(true);
        const stats = [];
        for (const journal of journals) {
            const count = await db.papers.count({
                journalIds: [journal.id],
                dateFrom,
                dateTo,
            });
            stats.push({
                journalId: journal.id,
                journalName: journal.name,
                category: journal.category,
                count,
            });
        }
        // 全体統計
        const totalCount = await db.papers.count({ dateFrom, dateTo });
        res.json({
            success: true,
            total: totalCount,
            byJournal: stats,
        });
    }
    catch (error) {
        logger.error('Get stats error:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});
/**
 * GET /api/papers/:id
 * 論文詳細を取得
 */
router.get('/:id', async (req, res) => {
    try {
        const { id } = req.params;
        const paper = await db.papers.findById(id);
        if (!paper) {
            res.status(404).json({ error: 'Paper not found' });
            return;
        }
        // 要約も取得
        const summaries = await db.summaries.findByPaperId(id);
        res.json({
            success: true,
            paper: {
                ...paper,
                summaries,
            },
        });
    }
    catch (error) {
        logger.error('Get paper error:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
});
export default router;
//# sourceMappingURL=papers.js.map