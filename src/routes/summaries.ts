/**
 * 要約ルーター
 * /api/summaries
 */

import { Router } from 'express';
import * as db from '../lib/database.js';
import * as aiSummary from '../lib/ai-summary.js';
import logger from '../lib/logger.js';
import type { Request, Response } from '../types/index.js';

const router = Router();

/**
 * GET /api/summaries/providers
 * 利用可能なAIプロバイダを取得
 */
router.get('/providers', async (_req: Request, res: Response): Promise<void> => {
  try {
    const providers = aiSummary.getAvailableProviders();
    const current = aiSummary.getCurrentProvider();

    res.json({
      success: true,
      providers,
      current,
    });
  } catch (error) {
    logger.error('Get providers error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

/**
 * POST /api/summaries/generate
 * 要約を生成
 */
router.post('/generate', async (req: Request, res: Response): Promise<void> => {
  try {
    const { paperId, provider, model } = req.body as {
      paperId?: number | string;
      provider?: string;
      model?: string;
    };

    if (!paperId) {
      res.status(400).json({ error: 'Paper ID is required' });
      return;
    }

    // 論文を取得
    const paper = await db.papers.findById(paperId);

    if (!paper) {
      res.status(404).json({ error: 'Paper not found' });
      return;
    }

    // 要約を生成
    const summaryData = await aiSummary.generateSummary(paper, {
      provider,
      model,
    });

    // データベースに保存
    const summary = await db.summaries.create({
      paper_id: typeof paperId === 'string' ? parseInt(paperId) : paperId,
      ...summaryData,
    });

    res.json({
      success: true,
      summary,
    });
  } catch (error) {
    logger.error('Generate summary error:', error);

    if ((error as Error).message.includes('API key')) {
      res.status(503).json({ error: 'AI service not configured' });
      return;
    }

    res.status(500).json({ error: (error as Error).message || 'Failed to generate summary' });
  }
});

/**
 * GET /api/summaries/:paperId
 * 論文の要約を取得
 */
router.get('/:paperId', async (req: Request, res: Response): Promise<void> => {
  try {
    const { paperId } = req.params;

    const summaries = await db.summaries.findByPaperId(paperId);

    res.json({
      success: true,
      summaries,
    });
  } catch (error) {
    logger.error('Get summaries error:', error);
    res.status(500).json({ error: 'Internal server error' });
  }
});

export default router;
