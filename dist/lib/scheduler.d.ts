/**
 * スケジューラーモジュール
 * node-cron を使用した定期RSS取得
 */
import type { FetchResult, FetchAllResult, SchedulerStatus } from '../types/index.js';
/**
 * 全論文誌のRSSを取得
 */
export declare function fetchAllFeeds(): Promise<FetchAllResult>;
/**
 * スケジューラーを開始
 */
export declare function start(): boolean;
/**
 * スケジューラーを停止
 */
export declare function stop(): void;
/**
 * ステータスを取得
 */
export declare function getStatus(): SchedulerStatus;
/**
 * 手動実行
 */
export declare function runNow(): Promise<FetchAllResult>;
/**
 * 特定の論文誌のみ取得
 */
export declare function fetchJournal(journalId: string): Promise<FetchResult>;
declare const _default: {
    start: typeof start;
    stop: typeof stop;
    getStatus: typeof getStatus;
    runNow: typeof runNow;
    fetchJournal: typeof fetchJournal;
    fetchAllFeeds: typeof fetchAllFeeds;
};
export default _default;
//# sourceMappingURL=scheduler.d.ts.map