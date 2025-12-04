<?php

namespace App\Services;

use App\Models\Journal;
use App\Models\Paper;
use App\Models\FetchLog;
use Illuminate\Support\Facades\Log;
use SimplePie\SimplePie;

class RssFetcherService
{
    private bool $isRunning = false;
    private ?\DateTime $lastRunTime = null;

    public function getStatus(): array
    {
        return [
            'isRunning' => $this->isRunning,
            'isScheduled' => config('services.fetch.enabled', true),
            'schedule' => config('services.fetch.schedule', '0 6 * * *'),
            'lastRunTime' => $this->lastRunTime?->format('c'),
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

            foreach ($items as $item) {
                $paper = $this->parseFeedItem($item, $journal);
                if ($paper) {
                    $inserted = $this->upsertPaper($paper);
                    if ($inserted) {
                        $newPapers++;
                    }
                }
            }

            $executionTimeMs = (int)((microtime(true) - $startTime) * 1000);

            // Update last fetched
            $journal->update(['last_fetched_at' => now()]);

            // Log success
            FetchLog::logSuccess($journal->id, $papersFetched, $newPapers, $executionTimeMs);

            Log::info("Fetched {$papersFetched} papers ({$newPapers} new) for {$journal->name}");

            return [
                'status' => 'success',
                'papers_fetched' => $papersFetched,
                'new_papers' => $newPapers,
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
            'sample_items' => array_map(fn($item) => [
                'title' => $item->get_title(),
                'date' => $item->get_date('Y-m-d'),
                'author' => $item->get_author()?->get_name(),
            ], $items),
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

        // Get abstract from content or description
        $abstract = $item->get_content() ?? $item->get_description();
        if ($abstract) {
            // Strip HTML tags
            $abstract = strip_tags($abstract);
            // Clean up whitespace
            $abstract = preg_replace('/\s+/', ' ', trim($abstract));
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

    private function upsertPaper(array $data): bool
    {
        try {
            $existing = Paper::where('journal_id', $data['journal_id'])
                ->whereRaw('SUBSTRING(title, 1, 255) = ?', [substr($data['title'], 0, 255)])
                ->first();

            if ($existing) {
                // Update existing
                $existing->update([
                    'abstract' => $data['abstract'] ?? $existing->abstract,
                    'url' => $data['url'] ?? $existing->url,
                    'doi' => $data['doi'] ?? $existing->doi,
                ]);
                return false;
            }

            // Create new
            Paper::create($data);
            return true;

        } catch (\Exception $e) {
            Log::warning("Failed to upsert paper: {$e->getMessage()}", ['title' => $data['title']]);
            return false;
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
