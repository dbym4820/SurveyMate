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

            // AI解析で生成した抽出ルールがあるか確認
            $useExtractionRules = $journal->hasExtractionRules();
            $rawXml = null;

            if ($useExtractionRules) {
                // 抽出ルールがある場合は生XMLも取得
                $rawXml = $this->fetchRawXml($journal->rss_url);
            }

            foreach ($items as $index => $item) {
                $paper = null;

                // 抽出ルールがある場合は優先して使用
                if ($useExtractionRules && $rawXml) {
                    try {
                        $paper = $this->parseWithExtractionRules($rawXml, $index, $journal);
                    } catch (\Exception $e) {
                        Log::warning("Extraction rule failed for item {$index}", [
                            'journal_id' => $journal->id,
                            'error' => $e->getMessage(),
                        ]);
                        // フォールバック: SimplePieで解析
                        $paper = $this->parseFeedItem($item, $journal);
                    }
                }

                // 抽出ルールがない場合，またはルール適用に失敗した場合
                if (!$paper) {
                    $paper = $this->parseFeedItem($item, $journal);
                }

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
     * RSSフィードの生XMLを取得
     */
    private function fetchRawXml(string $url): ?string
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SurveyMate/2.0; Academic Research)',
                    'Accept' => 'application/rss+xml, application/xml, text/xml, */*',
                ])
                ->get($url);

            if ($response->successful()) {
                return $response->body();
            }
        } catch (\Exception $e) {
            Log::warning("Failed to fetch raw XML", ['url' => $url, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * AI生成の抽出ルールを使ってアイテムをパース
     */
    private function parseWithExtractionRules(string $xml, int $itemIndex, Journal $journal): ?array
    {
        $config = $journal->rss_extraction_config;
        if (!$config || !isset($config['fields'])) {
            return null;
        }

        // XMLをDOMでパース
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        @$dom->loadXML($xml);

        $xpath = new \DOMXPath($dom);

        // 名前空間を登録
        if (isset($config['namespaces']) && is_array($config['namespaces'])) {
            foreach ($config['namespaces'] as $prefix => $uri) {
                $xpath->registerNamespace($prefix, $uri);
            }
        }

        // アイテム要素を取得
        $itemElement = $config['item_element'] ?? 'item';
        $items = $dom->getElementsByTagName($itemElement);

        if ($items->length === 0) {
            // Atomの場合はentry
            $items = $dom->getElementsByTagName('entry');
        }

        if ($itemIndex >= $items->length) {
            return null;
        }

        $item = $items->item($itemIndex);
        $fields = $config['fields'];

        // タイトル（必須）
        $title = $this->extractFieldValue($item, $fields['title'] ?? null, $xpath);
        if (!$title) {
            return null;
        }

        // タイトルをクリーンアップ
        $title = trim(strip_tags(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        // 各フィールドを抽出
        $authors = [];
        if (isset($fields['authors'])) {
            $authorsValue = $this->extractFieldValue($item, $fields['authors'], $xpath, true);
            if (is_array($authorsValue)) {
                $authors = array_map(function ($a) {
                    return trim(strip_tags(html_entity_decode($a, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                }, $authorsValue);
            } elseif (is_string($authorsValue)) {
                $authors = array_map('trim', explode(',', $authorsValue));
            }
        }

        $publishedDate = null;
        if (isset($fields['published_date'])) {
            $dateValue = $this->extractFieldValue($item, $fields['published_date'], $xpath);
            if ($dateValue) {
                $publishedDate = $this->parseDateValue($dateValue);
            }
        }

        $doi = null;
        if (isset($fields['doi'])) {
            $doiValue = $this->extractFieldValue($item, $fields['doi'], $xpath);
            if ($doiValue) {
                // DOIパターンで抽出
                $pattern = $fields['doi']['pattern'] ?? '10\.\d{4,}/[^\s<>"\']+';
                if (preg_match('/' . $pattern . '/', $doiValue, $matches)) {
                    $doi = $this->cleanDoi($matches[0]);
                }
            }
        }

        $abstract = null;
        if (isset($fields['abstract'])) {
            $abstractValue = $this->extractFieldValue($item, $fields['abstract'], $xpath);
            if ($abstractValue) {
                $abstract = $this->cleanAbstract($abstractValue);
            }
        }

        $url = null;
        if (isset($fields['url'])) {
            $url = $this->extractFieldValue($item, $fields['url'], $xpath);
            // URLのhref属性をチェック
            if (!$url && isset($fields['url']['attribute'])) {
                // 別途処理
            }
        }

        // summary_parsing が有効な場合，summaryから追加情報を抽出
        if (isset($config['summary_parsing']) && $config['summary_parsing']['enabled']) {
            $summaryResult = $this->extractFromSummary($item, $config['summary_parsing'], $xpath);

            // 既存値がない場合のみ上書き
            if (empty($authors) && !empty($summaryResult['authors'])) {
                $authors = $summaryResult['authors'];
            }
            if (!$doi && !empty($summaryResult['doi'])) {
                $doi = $summaryResult['doi'];
            }
            if (!$abstract && !empty($summaryResult['abstract'])) {
                $abstract = $summaryResult['abstract'];
            }
        }

        // External ID: prefer DOI, then link
        $externalId = $doi ?? $url ?? null;

        return [
            'journal_id' => $journal->id,
            'external_id' => $externalId,
            'title' => $title,
            'authors' => $authors,
            'abstract' => $abstract,
            'url' => $url,
            'doi' => $doi,
            'published_date' => $publishedDate,
        ];
    }

    /**
     * 抽出ルールに基づいてフィールド値を取得
     */
    private function extractFieldValue(\DOMNode $item, ?array $fieldConfig, \DOMXPath $xpath, bool $multiple = false)
    {
        if (!$fieldConfig) {
            return null;
        }

        $element = $fieldConfig['element'] ?? null;
        if (!$element) {
            return null;
        }

        // 複数の候補がある場合（パイプ区切り）
        $candidates = explode('|', $element);

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            // 名前空間付きの要素
            if (strpos($candidate, ':') !== false) {
                [$prefix, $localName] = explode(':', $candidate, 2);
                $namespace = $fieldConfig['namespace'] ?? null;

                if ($namespace) {
                    $nodes = $xpath->query(".//{$prefix}:{$localName}", $item);
                } else {
                    // 名前空間なしでタグ名検索
                    $nodes = $item->getElementsByTagName($localName);
                }
            } else {
                $nodes = $item->getElementsByTagName($candidate);
            }

            if ($nodes && $nodes->length > 0) {
                if ($multiple) {
                    $values = [];
                    foreach ($nodes as $node) {
                        $value = $this->getNodeValue($node, $fieldConfig);
                        if ($value) {
                            $values[] = $value;
                        }
                    }
                    if (!empty($values)) {
                        return $values;
                    }
                } else {
                    $value = $this->getNodeValue($nodes->item(0), $fieldConfig);
                    if ($value) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * ノードから値を取得（属性または内容）
     */
    private function getNodeValue(\DOMNode $node, array $fieldConfig): ?string
    {
        $attribute = $fieldConfig['attribute'] ?? null;

        if ($attribute && $node instanceof \DOMElement) {
            $value = $node->getAttribute($attribute);
            if ($value) {
                return $value;
            }
        }

        // 子要素がある場合
        $childElement = $fieldConfig['child_element'] ?? null;
        if ($childElement && $node instanceof \DOMElement) {
            $children = $node->getElementsByTagName($childElement);
            if ($children->length > 0) {
                return $children->item(0)->textContent;
            }
        }

        return $node->textContent ?: null;
    }

    /**
     * summary/descriptionタグから正規表現で情報を抽出
     */
    private function extractFromSummary(\DOMNode $item, array $summaryConfig, \DOMXPath $xpath): array
    {
        $result = ['authors' => null, 'doi' => null, 'abstract' => null];

        $sourceElement = $summaryConfig['source_element'] ?? 'description|summary';
        $patterns = $summaryConfig['patterns'] ?? [];

        // summaryの内容を取得
        $summaryContent = null;
        $candidates = explode('|', $sourceElement);

        foreach ($candidates as $candidate) {
            $nodes = $item->getElementsByTagName(trim($candidate));
            if ($nodes->length > 0) {
                $summaryContent = $nodes->item(0)->textContent;
                break;
            }
        }

        if (!$summaryContent) {
            return $result;
        }

        // 各パターンで抽出
        if (!empty($patterns['authors'])) {
            if (preg_match('/' . $patterns['authors'] . '/i', $summaryContent, $matches)) {
                $authorsStr = $matches[1] ?? $matches[0];
                $result['authors'] = array_map('trim', preg_split('/[,;]/', $authorsStr));
            }
        }

        if (!empty($patterns['doi'])) {
            if (preg_match('/' . $patterns['doi'] . '/i', $summaryContent, $matches)) {
                $result['doi'] = $this->cleanDoi($matches[1] ?? $matches[0]);
            }
        }

        if (!empty($patterns['abstract'])) {
            if (preg_match('/' . $patterns['abstract'] . '/is', $summaryContent, $matches)) {
                $result['abstract'] = $this->cleanAbstract($matches[1] ?? $matches[0]);
            }
        }

        return $result;
    }

    /**
     * 日付値をパース
     */
    private function parseDateValue(string $value): ?string
    {
        try {
            $date = new \DateTime($value);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            // フォーマットを変えて再試行
            $formats = ['Y-m-d', 'Y/m/d', 'd M Y', 'M d, Y', 'Y-m-d\TH:i:s'];
            foreach ($formats as $format) {
                $date = \DateTime::createFromFormat($format, $value);
                if ($date) {
                    return $date->format('Y-m-d');
                }
            }
        }

        return null;
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
