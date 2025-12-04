#!/usr/bin/env node
/**
 * 手動RSS取得スクリプト
 * 使用方法: npx tsx src/scripts/fetch-now.ts [journal_id]
 */
import 'dotenv/config';
import * as scheduler from '../lib/scheduler.js';
import * as db from '../lib/database.js';
async function main() {
    const journalId = process.argv[2];
    try {
        console.log('RSS取得を開始します...\n');
        let result;
        if (journalId) {
            console.log(`論文誌: ${journalId}`);
            result = await scheduler.fetchJournal(journalId);
        }
        else {
            console.log('全論文誌を取得します');
            result = await scheduler.runNow();
        }
        console.log('\n=== 結果 ===');
        if ('skipped' in result && result.skipped) {
            console.log('別の取得処理が実行中のためスキップしました');
        }
        else if (journalId) {
            // Single journal result
            const singleResult = result;
            console.log(`成功: ${singleResult.success}`);
            console.log(`取得論文数: ${singleResult.papersFetched || 0}`);
            console.log(`新規論文数: ${singleResult.newPapers || 0}`);
        }
        else {
            // All journals result
            const allResult = result;
            console.log(`総論文誌数: ${allResult.total}`);
            console.log(`成功: ${allResult.success}`);
            console.log(`失敗: ${allResult.failed}`);
            console.log(`新規論文数: ${allResult.newPapers}`);
            if (allResult.details) {
                console.log('\n=== 詳細 ===');
                for (const detail of allResult.details) {
                    const status = detail.success ? '✓' : '✗';
                    console.log(`${status} ${detail.journalName}: ${detail.newPapers || 0}件の新規論文`);
                    if (detail.error) {
                        console.log(`  エラー: ${detail.error}`);
                    }
                }
            }
        }
    }
    catch (error) {
        console.error('エラー:', error.message);
        process.exit(1);
    }
    finally {
        await db.end();
    }
}
main();
//# sourceMappingURL=fetch-now.js.map