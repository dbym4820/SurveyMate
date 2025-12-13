<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Paper;
use App\Services\FullTextFetcherService;

class FetchFullText extends Command
{
    protected $signature = 'fulltext:fetch
                            {--limit=100 : Maximum number of papers to process}
                            {--journal= : Specific journal ID to process}
                            {--retry : Retry previously failed papers}';

    protected $description = 'Fetch full text for papers that do not have it yet';

    private FullTextFetcherService $fetcher;

    public function __construct(FullTextFetcherService $fetcher)
    {
        parent::__construct();
        $this->fetcher = $fetcher;
    }

    public function handle(): int
    {
        if (!config('surveymate.full_text.enabled', true)) {
            $this->warn('Full text fetching is disabled in configuration.');
            return Command::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $journalId = $this->option('journal');
        $retry = $this->option('retry');

        $query = Paper::whereNull('full_text');

        if ($journalId) {
            $query->where('journal_id', $journalId);
        }

        if (!$retry) {
            // 未試行の論文のみ（full_text_fetched_atがnull）
            $query->whereNull('full_text_fetched_at');
        }

        $papers = $query->orderBy('published_date', 'desc')
            ->limit($limit)
            ->get();

        if ($papers->isEmpty()) {
            $this->info('No papers to process.');
            return Command::SUCCESS;
        }

        $this->info("Processing {$papers->count()} papers...");
        $bar = $this->output->createProgressBar($papers->count());
        $bar->start();

        $success = 0;
        $failed = 0;

        foreach ($papers as $paper) {
            $result = $this->fetcher->fetchFullText($paper);

            if ($result['success']) {
                $paper->update([
                    'full_text' => $result['text'],
                    'full_text_source' => $result['source'],
                    'pdf_url' => $result['pdf_url'],
                    'full_text_fetched_at' => now(),
                ]);
                $success++;
            } else {
                // 失敗も記録（再試行判定用）
                $paper->update(['full_text_fetched_at' => now()]);
                $failed++;
            }

            $bar->advance();

            // レート制限対策（0.5秒待機）
            usleep(500000);
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Completed: {$success} success, {$failed} failed");

        // 残りの未処理論文数を表示
        $remaining = Paper::whereNull('full_text')->whereNull('full_text_fetched_at')->count();
        if ($remaining > 0) {
            $this->info("Remaining papers without full text: {$remaining}");
        }

        return Command::SUCCESS;
    }
}
