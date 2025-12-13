<?php

namespace App\Services;

use App\Models\Journal;
use App\Models\Paper;
use App\Models\FetchLog;
use Illuminate\Support\Facades\Log;
use SimplePie\SimplePie;

class RssFetcherService
{
    /** @var bool */
    private $isRunning = false;

    /** @var \DateTime|null */
    private $lastRunTime = null;

    private FullTextFetcherService $fullTextFetcher;
    private bool $fullTextEnabled;

    public function __construct(FullTextFetcherService $fullTextFetcher)
    {
        $this->fullTextFetcher = $fullTextFetcher;
        $this->fullTextEnabled = config('surveymate.full_text.enabled', true);
    }

    public function getStatus(): array
    {
        return [
            'isRunning' => $this->isRunning,
            'isScheduled' => config('services.fetch.enabled', true),
            'schedule' => config('services.fetch.schedule', '0 6 * * *'),
            'lastRunTime' => $this->lastRunTime ? $this->lastRunTime->format('c') : null,
            'nextRunTime' => $this->calculateNextRunTime(),
        ];
    }

    public function fetchAll(): array
    {
        if ($this->isRunning) {
            return ['error' => 'Already running'];
        }

        $this->isRunning = true;
        $this->lastRunTime = new \DateTime();

        $journals = Journal::active()->get();
        $results = [];

        foreach ($journals as $journal) {
            $result = $this->fetchJournal($journal);
            $results[$journal->id] = $result;

            // Minimum interval between fetches
            $interval = config('services.fetch.min_interval', 5000);
            usleep($interval * 1000);
        }

        $this->isRunning = false;

        return $results;
    }

    public function fetchForUser(int $userId): array
    {
        if ($this->isRunning) {
            return ['error' => 'Already running'];
        }

        $this->isRunning = true;
        $this->lastRunTime = new \DateTime();

        $journals = Journal::active()->forUser($userId)->get();
        $results = [];

        foreach ($journals as $journal) {
            $result = $this->fetchJournal($journal);
            $results[$journal->id] = $result;

            // Minimum interval between fetches
            $interval = config('services.fetch.min_interval', 5000);
            usleep($interval * 1000);
        }

        $this->isRunning = false;

        return $results;
    }

    public function fetchJournal(Journal $journal): array
    {
        $startTime = microtime(true);

        try {
            Log::info("Fetching RSS for journal: {$journal->name}");

            $feed = new SimplePie();
            $feed->set_feed_url($journal->rss_url);
            $feed->set_timeout(30);
            $feed->enable_cache(false);
            $feed->init();

            if ($feed->error()) {
                throw new \Exception($feed->error());
            }

            $items = $feed->get_items();
            $papersFetched = count($items);
            $newPapers = 0;
            $fullTextFetched = 0;

            foreach ($items as $item) {
                $paper = $this->parseFeedItem($item, $journal);
                if ($paper) {
                    $result = $this->upsertPaper($paper);
                    if ($result['inserted']) {
                        $newPapers++;

                        // 新規論文の本文取得（有効な場合）
                        if ($this->fullTextEnabled && $result['paper']) {
                            $ftResult = $this->fetchFullTextForPaper($result['paper']);
                            if ($ftResult) {
                                $fullTextFetched++;
                            }
                        }
                    }
                }
            }

            $executionTimeMs = (int)((microtime(true) - $startTime) * 1000);

            // Update last fetched
            $journal->update(['last_fetched_at' => now()]);

            // Log success
            FetchLog::logSuccess($journal->id, $papersFetched, $newPapers, $executionTimeMs);

            Log::info("Fetched {$papersFetched} papers ({$newPapers} new, {$fullTextFetched} full text) for {$journal->name}");

            return [
                'status' => 'success',
                'papers_fetched' => $papersFetched,
                'new_papers' => $newPapers,
                'full_text_fetched' => $fullTextFetched,
                'execution_time_ms' => $executionTimeMs,
            ];

        } catch (\Exception $e) {
            $executionTimeMs = (int)((microtime(true) - $startTime) * 1000);

            Log::error("Error fetching RSS for {$journal->name}: {$e->getMessage()}");

            FetchLog::logError($journal->id, $e->getMessage(), $executionTimeMs);

            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'execution_time_ms' => $executionTimeMs,
            ];
        }
    }

    public function testFeed(string $rssUrl): array
    {
        $feed = new SimplePie();
        $feed->set_feed_url($rssUrl);
        $feed->set_timeout(30);
        $feed->enable_cache(false);
        $feed->init();

        if ($feed->error()) {
            throw new \Exception($feed->error());
        }

        $items = $feed->get_items(0, 3);

        return [
            'title' => $feed->get_title(),
            'item_count' => $feed->get_item_quantity(),
            'sample_items' => array_map(function ($item) {
                $author = $item->get_author();
                return [
                    'title' => $item->get_title(),
                    'date' => $item->get_date('Y-m-d'),
                    'author' => $author ? $author->get_name() : null,
                ];
            }, $items),
        ];
    }

    private function parseFeedItem($item, Journal $journal): ?array
    {
        $title = $item->get_title();
        if (!$title) {
            return null;
        }

        // Extract authors
        $authors = [];
        $itemAuthors = $item->get_authors();
        if ($itemAuthors) {
            foreach ($itemAuthors as $author) {
                $name = $author->get_name();
                if ($name) {
                    $authors[] = $name;
                }
            }
        }

        // Extract DOI from various sources
        $doi = null;
        $link = $item->get_link();

        // Try to extract DOI from link
        if ($link && preg_match('/10\.\d{4,}\/[^\s]+/', $link, $matches)) {
            $doi = $matches[0];
        }

        // Get abstract from description (preferred for academic papers)
        // get_description() typically contains the paper abstract
        // get_content() often contains journal boilerplate or full HTML
        $abstract = null;
        $description = $item->get_description();
        $content = $item->get_content();

        // Prefer description over content for academic RSS feeds
        $rawAbstract = $description ?: $content;

        if ($rawAbstract) {
            // Strip HTML tags
            $cleanAbstract = strip_tags($rawAbstract);
            // Clean up whitespace
            $cleanAbstract = preg_replace('/\s+/', ' ', trim($cleanAbstract));

            // Filter out journal boilerplate/descriptions (common patterns)
            $boilerplatePatterns = [
                '/^The International Journal of/i',
                '/^This journal publishes/i',
                '/^Subscribe to/i',
                '/^Access the full/i',
                '/^Click here/i',
                '/^Read the full/i',
                '/publishes original research/i',
            ];

            $isBoilerplate = false;
            foreach ($boilerplatePatterns as $pattern) {
                if (preg_match($pattern, $cleanAbstract)) {
                    $isBoilerplate = true;
                    break;
                }
            }

            // Only use abstract if it's not boilerplate and has meaningful length
            if (!$isBoilerplate && strlen($cleanAbstract) > 50) {
                $abstract = $cleanAbstract;
            }
        }

        // Parse date
        $publishedDate = null;
        $date = $item->get_date('Y-m-d');
        if ($date) {
            $publishedDate = $date;
        }

        // External ID: prefer DOI, then GUID, then link
        $externalId = $doi ?? $item->get_id() ?? $link;

        return [
            'journal_id' => $journal->id,
            'external_id' => $externalId,
            'title' => $title,
            'authors' => $authors,
            'abstract' => $abstract,
            'url' => $link,
            'doi' => $doi,
            'published_date' => $publishedDate,
        ];
    }

    /**
     * 論文の本文を取得して保存
     */
    private function fetchFullTextForPaper(Paper $paper): bool
    {
        $result = $this->fullTextFetcher->fetchFullText($paper);

        if ($result['success']) {
            $updateData = [
                'full_text' => $result['text'],
                'full_text_source' => $result['source'],
                'full_text_fetched_at' => now(),
            ];

            // PDF URLとパスを保存
            if (!empty($result['pdf_url'])) {
                $updateData['pdf_url'] = $result['pdf_url'];
            }
            if (!empty($result['pdf_path'])) {
                $updateData['pdf_path'] = $result['pdf_path'];
            }

            $paper->update($updateData);
            return true;
        }

        Log::debug("Full text fetch failed for paper {$paper->id}: {$result['error']}");
        return false;
    }

    private function upsertPaper(array $data): array
    {
        try {
            $existing = Paper::where('journal_id', $data['journal_id'])
                ->whereRaw('SUBSTRING(title, 1, 255) = ?', [substr($data['title'], 0, 255)])
                ->first();

            if ($existing) {
                // Update existing - always update abstract to fix boilerplate issues
                $existing->update([
                    'abstract' => $data['abstract'],  // Allow null to clear boilerplate
                    'url' => $data['url'] ?? $existing->url,
                    'doi' => $data['doi'] ?? $existing->doi,
                ]);
                return ['inserted' => false, 'paper' => $existing];
            }

            // Create new
            $paper = Paper::create($data);
            return ['inserted' => true, 'paper' => $paper];

        } catch (\Exception $e) {
            Log::warning("Failed to upsert paper: {$e->getMessage()}", ['title' => $data['title']]);
            return ['inserted' => false, 'paper' => null];
        }
    }

    private function calculateNextRunTime(): ?string
    {
        $schedule = config('services.fetch.schedule', '0 6 * * *');

        try {
            $cron = new \Cron\CronExpression($schedule);
            return $cron->getNextRunDate()->format('c');
        } catch (\Exception $e) {
            return null;
        }
    }
}
