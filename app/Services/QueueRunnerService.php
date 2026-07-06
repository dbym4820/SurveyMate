<?php

namespace App\Services;

use App\Jobs\ProcessPaperFullTextJob;
use App\Models\Paper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * キューワーカーをバックグラウンドで起動するサービス
 * crontab不要でLaravelキューを処理可能
 */
class QueueRunnerService
{
    /**
     * バックグラウンドでキューワーカーを起動
     * 既にワーカーが動いている場合はスキップ
     */
    public static function startWorkerIfNeeded(string $queue = 'pdf-processing'): bool
    {
        // 同期モードの場合は不要（ジョブは即座に実行される）
        if (config('queue.default') === 'sync') {
            Log::debug("Queue is in sync mode, worker not needed");
            return false;
        }

        // exec()が使えない場合
        if (!function_exists('exec')) {
            Log::warning("exec() is disabled, cannot start background queue worker");
            return false;
        }

        // ワーカーが既に動いている場合はスキップ
        if (self::isWorkerRunning($queue)) {
            Log::debug("Queue worker already running for queue: {$queue}");
            return true;
        }

        // 未処理ジョブがない場合はスキップ
        if (!self::hasPendingJobs($queue)) {
            Log::debug("No pending jobs for queue: {$queue}");
            return false;
        }

        return self::startBackgroundWorker($queue);
    }

    /**
     * 強制的にワーカーを起動（既存ワーカーチェックをスキップ）
     */
    public static function forceStartWorker(string $queue = 'pdf-processing'): bool
    {
        if (config('queue.default') === 'sync') {
            return false;
        }

        if (!function_exists('exec')) {
            Log::warning("exec() is disabled, cannot start background queue worker");
            return false;
        }

        // 既に動いている場合はスキップ
        if (self::isWorkerRunning($queue)) {
            return true;
        }

        return self::startBackgroundWorker($queue);
    }

    /**
     * バックグラウンドプロセスでキューワーカーを起動
     */
    private static function startBackgroundWorker(string $queue): bool
    {
        $phpBinary = PHP_BINARY;
        $artisan = base_path('artisan');
        $logFile = storage_path('logs/queue-worker.log');

        // queue:work コマンドを構築
        // --stop-when-empty: キューが空になったら終了
        // --max-time=1800: 最大30分で終了（安全のため）
        // --memory=1024: メモリ上限1GB
        // --sleep=3: キューが空の場合3秒待機してから再チェック

        // サブシェルで実行し、親プロセスから完全に切り離す
        // (cmd > /dev/null 2>&1 &) の形式でデタッチ
        $command = sprintf(
            '(%s %s queue:work --queue=%s --stop-when-empty --max-time=1800 --memory=1024 --sleep=3 >> %s 2>&1 &)',
            escapeshellarg($phpBinary),
            escapeshellarg($artisan),
            escapeshellarg($queue),
            escapeshellarg($logFile)
        );

        exec($command);
        Log::info("Started background queue worker for queue: {$queue}");

        // 起動確認（少し待ってからチェック）
        usleep(500000); // 0.5秒待機
        $running = self::isWorkerRunning($queue);
        if (!$running) {
            Log::warning("Queue worker may have failed to start for queue: {$queue}");
        }

        return $running;
    }

    /**
     * ワーカーが既に動いているかチェック
     */
    public static function isWorkerRunning(string $queue): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $output = [];
        // プロセス名がphpのものだけをフィルタし、queue:workを検索
        // シェルのコマンド履歴を誤検出しないようにcommでphpを特定
        exec("ps -eo pid,comm,args | grep -E '^\\s*[0-9]+\\s+php' | grep 'queue:work' | grep -- '--queue={$queue}'", $output);

        return count($output) > 0;
    }

    /**
     * 未処理ジョブがあるかチェック
     */
    public static function hasPendingJobs(string $queue = 'pdf-processing'): bool
    {
        if (config('queue.default') === 'database') {
            return DB::table('jobs')
                ->where('queue', $queue)
                ->exists();
        }

        return false;
    }

    /**
     * スタックしたPDF処理ステータスを回復する
     *
     * ワーカープロセスがOOM kill・サーバ再起動等で異常終了すると，
     * ジョブのfailed()ハンドラが呼ばれずpdf_statusが
     * 「processing」「pending」のまま残る．これらを検出し，
     * 再試行上限内なら再ディスパッチ，上限超過ならfailed（PDFなし）に確定する．
     *
     * @return array{redispatched: int, failed: int}
     */
    public static function recoverStalePapers(int $maxAttempts = 2, string $queue = 'pdf-processing'): array
    {
        $redispatched = 0;
        $failed = 0;

        // 24時間以上キューに残ったままのジョブはワーカーが機能していない
        // と判断して削除する（論文側は下のpending回復で再ディスパッチ／failed確定される）
        if (config('queue.default') === 'database') {
            $deleted = DB::table('jobs')
                ->where('queue', $queue)
                ->where('created_at', '<', now()->subDay()->getTimestamp())
                ->delete();
            if ($deleted > 0) {
                Log::warning("Deleted {$deleted} stale jobs from queue '{$queue}' (older than 24h)");
            }
        }

        // processing のままの論文：ジョブタイムアウトは5分×2回なので，
        // 20分以上経過していればワーカーは死んでいると判断できる
        $staleProcessing = Paper::where('pdf_status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(20))
            ->get();

        // pending のままの論文：キューにジョブが残っていなければジョブは消失している．
        // ジョブが残っていても24時間以上進まないものはスタックとみなす
        $pendingThreshold = self::hasPendingJobs($queue)
            ? now()->subDay()
            : now()->subMinutes(60);
        $stalePending = Paper::where('pdf_status', 'pending')
            ->where('updated_at', '<', $pendingThreshold)
            ->get();

        foreach ($staleProcessing->merge($stalePending) as $paper) {
            if ($paper->pdf_fetch_attempts >= $maxAttempts) {
                $paper->update(['pdf_status' => 'failed']);
                Log::info("Recovered stale paper {$paper->id}: marked as failed after {$paper->pdf_fetch_attempts} attempts");
                $failed++;
            } else {
                $paper->update([
                    'pdf_status' => 'pending',
                    'pdf_fetch_attempts' => $paper->pdf_fetch_attempts + 1,
                ]);
                ProcessPaperFullTextJob::dispatch($paper->id);
                Log::info("Recovered stale paper {$paper->id}: redispatched (attempt {$paper->pdf_fetch_attempts})");
                $redispatched++;
            }
        }

        if ($redispatched > 0) {
            self::startWorkerIfNeeded($queue);
        }

        return ['redispatched' => $redispatched, 'failed' => $failed];
    }

    /**
     * 未処理ジョブ数を取得
     */
    public static function getPendingJobCount(string $queue = 'pdf-processing'): int
    {
        if (config('queue.default') === 'database') {
            return DB::table('jobs')
                ->where('queue', $queue)
                ->count();
        }

        return 0;
    }
}
