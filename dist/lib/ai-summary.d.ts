/**
 * AI要約生成モジュール
 * Claude API / OpenAI API 両対応
 */
import type { Paper, SummaryOptions, SummaryResult, AIProvider } from '../types/index.js';
/**
 * 要約を生成（メイン関数）
 */
export declare function generateSummary(paper: Paper, options?: SummaryOptions): Promise<SummaryResult>;
/**
 * 利用可能なプロバイダを取得
 */
export declare function getAvailableProviders(): AIProvider[];
/**
 * 現在のプロバイダ設定を取得
 */
export declare function getCurrentProvider(): string;
declare const _default: {
    generateSummary: typeof generateSummary;
    getAvailableProviders: typeof getAvailableProviders;
    getCurrentProvider: typeof getCurrentProvider;
};
export default _default;
//# sourceMappingURL=ai-summary.d.ts.map