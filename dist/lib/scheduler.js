/**
 * スケジューラーモジュール
 * node-cron を使用した定期RSS取得
 */
import cron from 'node-cron';
import Parser from 'rss-parser';
import * as db from './database.js';
import logger from './logger.js';
const parser = new Parser({
    customFields: {
        item: [
            ['dc:creator', 'creator'],
            ['prism:doi', 'doi'],
            ['prism:publicationName', 'publicationName'],
        ],
    },
    timeout: 30000, // 30秒タイムアウト
});
let scheduledTask = null;
let isRunning = false;
let lastRunTime = null;
let nextRunTime = null;
/**
 * 単一の論文誌からRSSを取得
 */
async function fetchJournalFeed(journal) {
    const startTime = Date.now();
    let papersFetched = 0;
    let newPapers = 0;
    try {
        logger.info(`Fetching RSS feed: ${journal.name}`);
        const feed = await parser.parseURL(journal.rss_url);
        papersFetched = feed.items.length;
        for (const item of feed.items) {
            // 著者の処理
            let authors = [];
            if (item.creator) {
                authors = Array.isArray(item.creator) ? item.creator : [item.creator];
            }
            else if (item.author) {
                authors = [item.author];
            }
            // 公開日の処理
            let publishedDate = null;
            if (item.pubDate || item.isoDate) {
                const date = new Date(item.pubDate || item.isoDate);
                if (!isNaN(date.getTime())) {
                    publishedDate = date.toISOString().split('T')[0];
                }
            }
            // DOIの抽出
            let doi = item.doi || null;
            if (!doi && item.link) {
                const doiMatch = item.link.match(/10\.\d{4,}\/[^\s]+/);
                if (doiMatch) {
                    doi = doiMatch[0];
                }
            }
            const paper = {
                journal_id: journal.id,
                title: item.title?.trim() || 'No title',
                authors,
                abstract: item.contentSnippet || item.content || item.description || '',
                url: item.link || '',
                doi,
                published_date: publishedDate,
                external_id: doi || item.guid || item.link || null,
            };
            try {
                const result = await db.papers.upsert(paper);
                if (result.insertId) {
                    newPapers++;
                }
            }
            catch (error) {
                // 重複エラーは無視
                if (!error.message.includes('Duplicate')) {
                    logger.warn(`Failed to insert paper: ${paper.title}`, { error: error.message });
                }
            }
        }
        // 最終取得日時を更新
        await db.journals.updateLastFetched(journal.id);
        const executionTime = Date.now() - startTime;
        // ログを記録
        await db.fetchLogs.create({
            journal_id: journal.id,
            status: 'success',
            papers_fetched: papersFetched,
            new_papers: newPapers,
            execution_time_ms: executionTime,
        });
        logger.info(`Completed: ${journal.name}`, {
            papersFetched,
            newPapers,
            executionTime: `${executionTime}ms`,
        });
        return { success: true, papersFetched, newPapers };
    }
    catch (error) {
        const executionTime = Date.now() - startTime;
        // エラーログを記録
        await db.fetchLogs.create({
            journal_id: journal.id,
            status: 'error',
            papers_fetched: papersFetched,
            new_papers: newPapers,
            error_message: error.message,
            execution_time_ms: executionTime,
        });
        logger.error(`Failed to fetch: ${journal.name}`, { error: error.message });
        return { success: false, error: error.message };
    }
}
/**
 * 全論文誌のRSSを取得
 */
export async function fetchAllFeeds() {
    if (isRunning) {
        logger.warn('Fetch already in progress, skipping');
        return { skipped: true, total: 0, success: 0, failed: 0, newPapers: 0, details: [] };
    }
    isRunning = true;
    lastRunTime = new Date();
    const results = {
        total: 0,
        success: 0,
        failed: 0,
        newPapers: 0,
        details: [],
    };
    try {
        logger.info('Starting scheduled RSS fetch');
        const journals = await db.journals.findAll(true);
        results.total = journals.length;
        const minInterval = parseInt(process.env.FETCH_MIN_INTERVAL || '5000');
        for (const journal of journals) {
            const result = await fetchJournalFeed(journal);
            results.details.push({
                journalId: journal.id,
                journalName: journal.name,
                ...result,
            });
            if (result.success) {
                results.success++;
                results.newPapers += result.newPapers || 0;
            }
            else {
                results.failed++;
            }
            // 連続アクセス防止のため待機
            await new Promise(resolve => setTimeout(resolve, minInterval));
        }
        logger.info('RSS fetch completed', {
            total: results.total,
            success: results.success,
            failed: results.failed,
            newPapers: results.newPapers,
        });
        return results;
    }
    finally {
        isRunning = false;
    }
}
/**
 * スケジューラーを開始
 */
export function start() {
    const schedule = process.env.FETCH_SCHEDULE || '0 0 6 * * *';
    if (!cron.validate(schedule)) {
        logger.error('Invalid cron schedule:', schedule);
        return false;
    }
    scheduledTask = cron.schedule(schedule, async () => {
        await fetchAllFeeds();
    }, {
        scheduled: true,
        timezone: 'Asia/Tokyo',
    });
    // 次回実行時間を計算
    updateNextRunTime(schedule);
    logger.info('Scheduler started', { schedule, nextRun: nextRunTime });
    return true;
}
/**
 * スケジューラーを停止
 */
export function stop() {
    if (scheduledTask) {
        scheduledTask.stop();
        scheduledTask = null;
        logger.info('Scheduler stopped');
    }
}
/**
 * 次回実行時間を更新
 */
function updateNextRunTime(schedule) {
    // node-cronには次回実行時間を取得する機能がないため，簡易計算
    const now = new Date();
    const parts = schedule.split(' ');
    if (parts.length >= 3) {
        const hour = parseInt(parts[2]) || 6;
        const minute = parseInt(parts[1]) || 0;
        nextRunTime = new Date(now);
        nextRunTime.setHours(hour, minute, 0, 0);
        if (nextRunTime <= now) {
            nextRunTime.setDate(nextRunTime.getDate() + 1);
        }
    }
}
/**
 * ステータスを取得
 */
export function getStatus() {
    return {
        isRunning,
        isScheduled: scheduledTask !== null,
        schedule: process.env.FETCH_SCHEDULE || '0 0 6 * * *',
        lastRunTime,
        nextRunTime,
    };
}
/**
 * 手動実行
 */
export async function runNow() {
    return fetchAllFeeds();
}
/**
 * 特定の論文誌のみ取得
 */
export async function fetchJournal(journalId) {
    const journal = await db.journals.findById(journalId);
    if (!journal) {
        throw new Error('Journal not found');
    }
    return fetchJournalFeed(journal);
}
export default {
    start,
    stop,
    getStatus,
    runNow,
    fetchJournal,
    fetchAllFeeds,
};
//# sourceMappingURL=scheduler.js.map