<?php

namespace App\Services;

use App\Models\Journal;
use App\Models\Paper;
use App\Models\FetchLog;
use App\Models\GeneratedFeed;
use App\Jobs\ProcessPaperFullTextJob;
use App\Services\QueueRunnerService;
use Illuminate\Support\Facades\Log;
use SimplePie\SimplePie;

class RssFetcherService
{
    /** @var bool */
    private $isRunning = false;

    /** @var \DateTime|null */
    private $lastRunTime = null;

    private FullTextFetcherService $fullTextFetcher;
    private AiRssGeneratorService $aiRssGenerator;
    private bool $fullTextEnabled;

    public function __construct(FullTextFetcherService $fullTextFetcher, AiRssGeneratorService $aiRssGenerator)
    {
        $this->fullTextFetcher = $fullTextFetcher;
        $this->aiRssGenerator = $aiRssGenerator;
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
            // AI生成フィードの場合は別処理
            if ($journal->isAiGenerated()) {
                Log::info("Fetching AI-generated feed for journal: {$journal->name}");
                return $this->fetchAiGeneratedJournal($journal, $startTime);
            }

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

            // PDF処理ジョブがあればワーカーを起動
            if ($fullTextFetched > 0) {
                $this->ensureQueueWorkerRunning();
            }

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

    /**
     * AI生成フィードの取得処理
     * AIを使ってHTMLから論文情報を抽出・整形して保存する
     */
    private function fetchAiGeneratedJournal(Journal $journal, float $startTime): array
    {
        try {
            // GeneratedFeedレコードを取得（ユーザー情報取得用）
            $generatedFeed = GeneratedFeed::where('journal_id', $journal->id)->first();

            if (!$generatedFeed) {
                throw new \Exception('AI generated feed configuration not found. Please regenerate the feed.');
            }

            // ユーザー情報を取得してAIサービスに設定
            $user = $generatedFeed->user;
            if (!$user) {
                throw new \Exception('User not found for AI generated feed.');
            }
            $this->aiRssGenerator->setUser($user);

            // ソースページをフェッチ（AIに渡すためクリーニング）
            $pageResult = $this->aiRssGenerator->fetchWebPage($journal->rss_url, true);
            if (!$pageResult['success']) {
                throw new \Exception('Failed to fetch source page: ' . ($pageResult['error'] ?? 'Unknown error'));
            }

            // AIで論文情報を抽出・整形
            $extractResult = $this->aiRssGenerator->extractPapersWithAi($pageResult['html'], $journal->rss_url);
            if (!$extractResult['success']) {
                throw new \Exception('AI extraction failed: ' . ($extractResult['error'] ?? 'Unknown error'));
            }

            $papers = $extractResult['papers'] ?? [];
            $papersFetched = count($papers);
            $newPapers = 0;
            $fullTextFetched = 0;

            // 抽出した論文情報をデータベースに保存
            foreach ($papers as $paperData) {
                if (empty($paperData['title'])) {
                    continue;
                }

                $data = [
                    'journal_id' => $journal->id,
                    'external_id' => $paperData['doi'] ?? $paperData['url'] ?? null,
                    'title' => $paperData['title'],
                    'authors' => $paperData['authors'] ?? [],
                    'abstract' => $paperData['abstract'] ?? null,
                    'url' => $paperData['url'] ?? null,
                    'doi' => $paperData['doi'] ?? null,
                    'published_date' => $paperData['published_date'] ?? null,
                ];

                $result = $this->upsertPaper($data);
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

            $executionTimeMs = (int)((microtime(true) - $startTime) * 1000);

            // Update last fetched
            $journal->update(['last_fetched_at' => now()]);

            // GeneratedFeedのステータス更新
            $generatedFeed->update([
                'generation_status' => 'success',
                'last_generated_at' => now(),
                'error_message' => null,
            ]);

            // Log success
            FetchLog::logSuccess($journal->id, $papersFetched, $newPapers, $executionTimeMs);

            Log::info("Fetched {$papersFetched} papers ({$newPapers} new, {$fullTextFetched} full text) for AI-generated feed {$journal->name} via AI extraction");

            // PDF処理ジョブがあればワーカーを起動
            if ($fullTextFetched > 0) {
                $this->ensureQueueWorkerRunning();
            }

            return [
                'status' => 'success',
                'papers_fetched' => $papersFetched,
                'new_papers' => $newPapers,
                'full_text_fetched' => $fullTextFetched,
                'execution_time_ms' => $executionTimeMs,
                'source_type' => 'ai_generated',
                'ai_provider' => $extractResult['provider'] ?? null,
            ];

        } catch (\Exception $e) {
            $executionTimeMs = (int)((microtime(true) - $startTime) * 1000);

            Log::error("Error fetching AI-generated feed for {$journal->name}: {$e->getMessage()}");

            // GeneratedFeedのエラーステータス更新
            if (isset($generatedFeed)) {
                $generatedFeed->markAsError($e->getMessage());
            }

            FetchLog::logError($journal->id, $e->getMessage(), $executionTimeMs);

            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'execution_time_ms' => $executionTimeMs,
                'source_type' => 'ai_generated',
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

        // タイトルからHTMLタグを除去してクリーンアップ
        $title = trim(strip_tags(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        // Extract authors from multiple sources
        $authors = $this->extractAuthors($item);

        // Extract DOI from various sources
        $doi = $this->extractDoi($item);

        // Get abstract from multiple sources
        $abstract = $this->extractAbstract($item);

        // Parse date
        $publishedDate = null;
        $date = $item->get_date('Y-m-d');
        if ($date) {
            $publishedDate = $date;
        }

        // Get link
        $link = $item->get_link();

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
     * 様々なソースから著者情報を抽出
     */
    private function extractAuthors($item): array
    {
        $authors = [];

        // 1. SimplePieの標準メソッド（Atom author, RSS author）
        $itemAuthors = $item->get_authors();
        if ($itemAuthors) {
            foreach ($itemAuthors as $author) {
                $name = $author->get_name();
                if ($name) {
                    $authors[] = $this->cleanAuthorName($name);
                }
            }
        }

        // 2. Dublin Core: dc:creator
        if (empty($authors)) {
            $dcCreators = $item->get_item_tags('http://purl.org/dc/elements/1.1/', 'creator');
            if ($dcCreators) {
                foreach ($dcCreators as $creator) {
                    if (!empty($creator['data'])) {
                        $authors[] = $this->cleanAuthorName($creator['data']);
                    }
                }
            }
        }

        // 3. Dublin Core Terms: dcterms:creator
        if (empty($authors)) {
            $dctermsCreators = $item->get_item_tags('http://purl.org/dc/terms/', 'creator');
            if ($dctermsCreators) {
                foreach ($dctermsCreators as $creator) {
                    if (!empty($creator['data'])) {
                        $authors[] = $this->cleanAuthorName($creator['data']);
                    }
                }
            }
        }

        // 4. PRISM: prism:author
        if (empty($authors)) {
            $prismAuthors = $item->get_item_tags('http://prismstandard.org/namespaces/basic/2.0/', 'author');
            if ($prismAuthors) {
                foreach ($prismAuthors as $author) {
                    if (!empty($author['data'])) {
                        $authors[] = $this->cleanAuthorName($author['data']);
                    }
                }
            }
        }

        return array_unique(array_filter($authors));
    }

    /**
     * 著者名をクリーンアップ
     */
    private function cleanAuthorName(string $name): string
    {
        // HTMLタグを除去
        $name = strip_tags($name);
        // HTMLエンティティをデコード
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 余分な空白を除去
        $name = preg_replace('/\s+/', ' ', trim($name));
        return $name;
    }

    /**
     * 様々なソースからDOIを抽出
     */
    private function extractDoi($item): ?string
    {
        $doi = null;
        $link = $item->get_link();

        // DOIの正規表現パターン
        $doiPattern = '/\b(10\.\d{4,}\/[^\s<>"\']+)/';

        // 1. リンクからDOIを抽出
        if ($link && preg_match($doiPattern, $link, $matches)) {
            $doi = $this->cleanDoi($matches[1]);
        }

        // 2. Dublin Core: dc:identifier
        if (!$doi) {
            $dcIdentifiers = $item->get_item_tags('http://purl.org/dc/elements/1.1/', 'identifier');
            if ($dcIdentifiers) {
                foreach ($dcIdentifiers as $identifier) {
                    if (!empty($identifier['data']) && preg_match($doiPattern, $identifier['data'], $matches)) {
                        $doi = $this->cleanDoi($matches[1]);
                        break;
                    }
                }
            }
        }

        // 3. PRISM: prism:doi
        if (!$doi) {
            $prismDoi = $item->get_item_tags('http://prismstandard.org/namespaces/basic/2.0/', 'doi');
            if ($prismDoi && !empty($prismDoi[0]['data'])) {
                $doi = $this->cleanDoi($prismDoi[0]['data']);
            }
        }

        // 4. GUIDからDOIを抽出
        if (!$doi) {
            $guid = $item->get_id();
            if ($guid && preg_match($doiPattern, $guid, $matches)) {
                $doi = $this->cleanDoi($matches[1]);
            }
        }

        // 5. description/contentからDOIを抽出
        if (!$doi) {
            $content = $item->get_description() ?: $item->get_content();
            if ($content && preg_match($doiPattern, $content, $matches)) {
                $doi = $this->cleanDoi($matches[1]);
            }
        }

        return $doi;
    }

    /**
     * DOIをクリーンアップ
     */
    private function cleanDoi(string $doi): string
    {
        // URLプレフィックスを除去
        $doi = preg_replace('/^https?:\/\/(?:dx\.)?doi\.org\//', '', $doi);
        // 末尾の不要な文字を除去
        $doi = rtrim($doi, '.,;:)]\'"');
        return $doi;
    }

    /**
     * 様々なソースからアブストラクトを抽出
     */
    private function extractAbstract($item): ?string
    {
        $abstract = null;

        // 1. Dublin Core: dc:description（最優先）
        $dcDescription = $item->get_item_tags('http://purl.org/dc/elements/1.1/', 'description');
        if ($dcDescription && !empty($dcDescription[0]['data'])) {
            $abstract = $this->cleanAbstract($dcDescription[0]['data']);
            if ($abstract) {
                return $abstract;
            }
        }

        // 2. Atom: summary
        $atomSummary = $item->get_item_tags('http://www.w3.org/2005/Atom', 'summary');
        if ($atomSummary && !empty($atomSummary[0]['data'])) {
            $abstract = $this->cleanAbstract($atomSummary[0]['data']);
            if ($abstract) {
                return $abstract;
            }
        }

        // 3. SimplePieの標準メソッド（description/content）
        $description = $item->get_description();
        $content = $item->get_content();

        // descriptionを優先
        $rawAbstract = $description ?: $content;

        if ($rawAbstract) {
            $abstract = $this->cleanAbstract($rawAbstract);
        }

        return $abstract;
    }

    /**
     * アブストラクトをクリーンアップ
     */
    private function cleanAbstract(string $text): ?string
    {
        // HTMLタグを除去
        $cleanAbstract = strip_tags($text);
        // HTMLエンティティをデコード
        $cleanAbstract = html_entity_decode($cleanAbstract, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 余分な空白を除去
        $cleanAbstract = preg_replace('/\s+/', ' ', trim($cleanAbstract));

        // ジャーナルのボイラープレートを除外
        $boilerplatePatterns = [
            '/^The International Journal of/i',
            '/^This journal publishes/i',
            '/^Subscribe to/i',
            '/^Access the full/i',
            '/^Click here/i',
            '/^Read the full/i',
            '/publishes original research/i',
            '/^No abstract available/i',
            '/^Abstract not available/i',
        ];

        foreach ($boilerplatePatterns as $pattern) {
            if (preg_match($pattern, $cleanAbstract)) {
                return null;
            }
        }

        // 意味のある長さがあるか確認
        if (strlen($cleanAbstract) < 50) {
            return null;
        }

        return $cleanAbstract;
    }

    /**
     * 論文の本文取得ジョブをキューに追加
     * PDF解析は時間がかかるため非同期で処理
     * @return bool ジョブがディスパッチされたかどうか
     */
    private function fetchFullTextForPaper(Paper $paper): bool
    {
        try {
            // ステータスを「待機中」に設定
            $paper->update(['pdf_status' => 'pending']);

            // ジョブをキューにディスパッチ（非同期処理）
            ProcessPaperFullTextJob::dispatch($paper->id);
            Log::debug("Dispatched PDF processing job for paper {$paper->id}");

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to dispatch PDF processing job for paper {$paper->id}: " . $e->getMessage());
            // 失敗した場合はステータスをリセット
            $paper->update(['pdf_status' => null]);
            return false;
        }
    }

    /**
     * キューワーカーを起動（RSS取得完了後に呼び出す）
     */
    private function ensureQueueWorkerRunning(): void
    {
        try {
            if (QueueRunnerService::hasPendingJobs('pdf-processing')) {
                $started = QueueRunnerService::startWorkerIfNeeded('pdf-processing');
                if ($started) {
                    Log::info("Queue worker started for PDF processing");
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to start queue worker: " . $e->getMessage());
        }
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
