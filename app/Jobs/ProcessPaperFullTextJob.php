<?php

namespace App\Jobs;

use App\Models\Paper;
use App\Services\FullTextFetcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPaperFullTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ジョブのタイムアウト（秒）
     * PDF解析に十分な時間を確保
     */
    public int $timeout = 300; // 5分

    /**
     * 失敗前の試行回数
     */
    public int $tries = 2;

    /**
     * 再試行までの待機秒数
     */
    public int $backoff = 60;

    protected int $paperId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $paperId)
    {
        $this->paperId = $paperId;
        $this->onQueue('pdf-processing');
    }

    /**
     * Execute the job.
     */
    public function handle(FullTextFetcherService $fullTextFetcher): void
    {
        $paper = Paper::find($this->paperId);

        if (!$paper) {
            Log::warning("ProcessPaperFullTextJob: Paper {$this->paperId} not found");
            return;
        }

        // 既に処理済みの場合はスキップ
        if ($paper->pdf_status === 'completed') {
            Log::debug("ProcessPaperFullTextJob: Paper {$this->paperId} already completed");
            return;
        }

        // ステータスを「処理中」に更新
        $paper->update(['pdf_status' => 'processing']);

        Log::info("ProcessPaperFullTextJob: Processing paper {$this->paperId} - {$paper->title}");

        try {
            $result = $fullTextFetcher->fetchFullText($paper);

            if ($result['success']) {
                $updateData = [
                    'full_text' => $result['text'],
                    'full_text_source' => $result['source'],
                    'full_text_fetched_at' => now(),
                    'pdf_status' => 'completed',
                ];

                if (!empty($result['pdf_url'])) {
                    $updateData['pdf_url'] = $result['pdf_url'];
                }
                if (!empty($result['pdf_path'])) {
                    $updateData['pdf_path'] = $result['pdf_path'];
                }

                $paper->update($updateData);

                Log::info("ProcessPaperFullTextJob: Successfully processed paper {$this->paperId}");
            } else {
                // PDF取得に失敗した場合もステータスを更新（再試行対象外）
                $paper->update(['pdf_status' => 'failed']);
                Log::debug("ProcessPaperFullTextJob: Failed to fetch full text for paper {$this->paperId}: " . ($result['error'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            Log::error("ProcessPaperFullTextJob: Exception processing paper {$this->paperId}: " . $e->getMessage());
            throw $e; // 再試行のために例外を再スロー
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessPaperFullTextJob: Job failed for paper {$this->paperId}: " . $exception->getMessage());

        // 最終的に失敗した場合はステータスを更新
        $paper = Paper::find($this->paperId);
        if ($paper) {
            $paper->update(['pdf_status' => 'failed']);
        }
    }
}
