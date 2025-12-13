<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Journal;
use App\Services\RssFetcherService;

class FetchRss extends Command
{
    protected $signature = 'rss:fetch
                            {journal? : The journal ID to fetch (optional, fetches all if not specified)}
                            {--list : List all available journals}
                            {--user= : Fetch all journals for a specific user ID}';

    protected $description = 'Fetch RSS feeds from academic journals';

    /** @var RssFetcherService */
    private $fetcher;

    public function __construct(RssFetcherService $fetcher)
    {
        parent::__construct();
        $this->fetcher = $fetcher;
    }

    public function handle(): int
    {
        // List journals
        if ($this->option('list')) {
            $journals = Journal::all();
            $this->table(
                ['ID', 'Name', 'Active', 'Last Fetched'],
                $journals->map(function ($j) {
                    return [
                        $j->id,
                        substr($j->name, 0, 50) . (strlen($j->name) > 50 ? '...' : ''),
                        $j->is_active ? 'Yes' : 'No',
                        $j->last_fetched_at ? $j->last_fetched_at->format('Y-m-d H:i:s') : 'Never',
                    ];
                })
            );
            return Command::SUCCESS;
        }

        $journalId = $this->argument('journal');
        $userId = $this->option('user');

        if ($journalId) {
            // Fetch single journal
            $journal = Journal::find($journalId);
            if (!$journal) {
                $this->error("Journal not found: {$journalId}");
                return Command::FAILURE;
            }

            $this->info("Fetching RSS for {$journal->name}...");
            $result = $this->fetcher->fetchJournal($journal);
            $this->displayResult($journal->name, $result);
        } elseif ($userId) {
            // Fetch all journals for a specific user
            $journals = Journal::where('user_id', $userId)->active()->get();
            if ($journals->isEmpty()) {
                $this->warn("No active journals found for user ID: {$userId}");
                return Command::SUCCESS;
            }

            $this->info("Fetching RSS for {$journals->count()} journals (user: {$userId})...");
            $this->newLine();

            foreach ($journals as $journal) {
                $this->info("  Fetching {$journal->name}...");
                $result = $this->fetcher->fetchJournal($journal);

                if ($result['status'] === 'success') {
                    $this->info("    ✓ {$result['new_papers']} new papers");
                } else {
                    $this->error("    ✗ Error: {$result['error']}");
                }
            }

            $this->newLine();
            $this->info('RSS fetch completed!');
        } else {
            // Fetch all journals
            $journals = Journal::active()->get();
            $this->info("Fetching RSS for {$journals->count()} journals...");
            $this->newLine();

            $bar = $this->output->createProgressBar($journals->count());
            $bar->start();

            foreach ($journals as $journal) {
                $result = $this->fetcher->fetchJournal($journal);
                $bar->advance();

                if ($result['status'] === 'error') {
                    $this->newLine();
                    $this->error("  Error fetching {$journal->name}: {$result['error']}");
                }
            }

            $bar->finish();
            $this->newLine(2);
            $this->info('RSS fetch completed!');
        }

        return Command::SUCCESS;
    }

    private function displayResult(string $journalName, array $result): void
    {
        $this->newLine();

        if ($result['status'] === 'success') {
            $this->info("✓ {$journalName}");
            $this->line("  Papers fetched: {$result['papers_fetched']}");
            $this->line("  New papers: {$result['new_papers']}");
            $this->line("  Execution time: {$result['execution_time_ms']}ms");
        } else {
            $this->error("✗ {$journalName}");
            $this->line("  Error: {$result['error']}");
            $this->line("  Execution time: {$result['execution_time_ms']}ms");
        }
    }
}
