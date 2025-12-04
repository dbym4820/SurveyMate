/**
 * 論文誌ルーター
 * /api/journals
 */

import { Router } from 'express';
import * as db from '../lib/database.js';
import logger from '../lib/logger.js';
import type { Request, Response } from '../types/index.js';

const router = Router();

/**
 * GET /api/journals
 * 論文誌一覧を取得
 */
router.get('/', async (req: Request, res: Response): Promise<void> => {
  try {
    const { all } = req.query as { all?: string };
    const activeOnly = all !== 'true';

    const journals = await db.journals.findAll(activeOnly);

    res.json({
      success: true,
      journals,
    });
  } catch (error) {
    logger.error('Get journals error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

/**
 * GET /api/journals/:id
 * 論文誌詳細を取得
 */
router.get('/:id', async (req: Request, res: Response): Promise<void> => {
  try {
    const { id } = req.params;

    const journal = await db.journals.findById(id);

    if (!journal) {
      res.status(404).json({ error: 'Journal not found' });
      return;
    }

    // 最近の論文も取得
    const recentPapers = await db.papers.findAll({
      journalIds: [id],
      limit: 10,
    });

    res.json({
      success: true,
      journal: {
        ...journal,
        recentPapers,
      },
    });
  } catch (error) {
    logger.error('Get journal error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

export default router;
