<?php

namespace App\Services;

use App\Models\Journal;
use App\Models\GeneratedFeed;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use DOMDocument;
use DOMXPath;

class AiRssGeneratorService
{
    private const CLAUDE_API_URL = 'https://api.anthropic.com/v1/messages';
    private const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';

    /** @var User|null */
    private $user;

    /**
     * Set the user context for API key retrieval
     */
    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Check if user has any AI API key configured
     */
    public function hasApiKey(): bool
    {
        if (!$this->user) {
            return false;
        }
        return $this->user->hasClaudeApiKey() || $this->user->hasOpenaiApiKey();
    }

    /**
     * Get preferred provider for the user
     */
    public function getPreferredProvider(): ?string
    {
        if (!$this->user) {
            return null;
        }

        if ($this->user->preferred_ai_provider) {
            if ($this->user->preferred_ai_provider === 'claude' && $this->user->hasClaudeApiKey()) {
                return 'claude';
            }
            if ($this->user->preferred_ai_provider === 'openai' && $this->user->hasOpenaiApiKey()) {
                return 'openai';
            }
        }

        if ($this->user->hasOpenaiApiKey()) {
            return 'openai';
        }
        if ($this->user->hasClaudeApiKey()) {
            return 'claude';
        }

        return null;
    }

    /**
     * Fetch web page content (raw HTML for selector-based parsing)
     */
    public function fetchWebPage(string $url, bool $clean = false): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SurveyMate/1.0; Academic Paper Aggregator)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'ja,en;q=0.9',
                ])
                ->get($url);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'HTTP error: ' . $response->status(),
                ];
            }

            $html = $response->body();
            $originalSize = strlen($html);

            if ($clean) {
                $html = $this->cleanHtmlForAi($html);
            }

            return [
                'success' => true,
                'html' => $html,
                'original_size' => $originalSize,
                'cleaned_size' => strlen($html),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to fetch web page', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Clean HTML for AI analysis (reduce token usage)
     */
    private function cleanHtmlForAi(string $html): string
    {
        // Remove script, style, svg, noscript, iframe, and comment tags
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html);
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $html);
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // Remove common non-content elements
        $html = preg_replace('/<header\b[^>]*>.*?<\/header>/is', '', $html);
        $html = preg_replace('/<footer\b[^>]*>.*?<\/footer>/is', '', $html);
        $html = preg_replace('/<nav\b[^>]*>.*?<\/nav>/is', '', $html);

        // Remove inline styles and event handlers
        $html = preg_replace('/\s+style\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace('/\s+style\s*=\s*\'[^\']*\'/i', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*\'[^\']*\'/i', '', $html);

        // Remove data attributes (often contain long base64 data)
        $html = preg_replace('/\s+data-[a-z-]+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace('/\s+data-[a-z-]+\s*=\s*\'[^\']*\'/i', '', $html);

        // Collapse whitespace
        $html = preg_replace('/\s+/', ' ', $html);

        // Truncate to ~40,000 characters (~10,000 tokens for GPT)
        // This leaves room for the prompt and response
        if (strlen($html) > 40000) {
            $html = substr($html, 0, 40000) . '... [truncated]';
        }

        return trim($html);
    }

    /**
     * Analyze page structure using AI to extract CSS selectors
     * This is only called once to learn the page structure
     */
    public function analyzePageStructure(string $html, string $url): array
    {
        $provider = $this->getPreferredProvider();
        if (!$provider) {
            return [
                'success' => false,
                'error' => 'No AI API key configured',
            ];
        }

        $prompt = $this->buildStructureAnalysisPrompt($html, $url);

        try {
            if ($provider === 'claude') {
                $result = $this->callClaudeForAnalysis($prompt);
            } else {
                $result = $this->callOpenAIForAnalysis($prompt);
            }

            // Check if this is an article list page
            $isArticleListPage = $result['is_article_list_page'] ?? false;
            $pageType = $result['page_type'] ?? 'unknown';
            $pageTypeReason = $result['page_type_reason'] ?? '';
            $articleListUrl = $result['article_list_url'] ?? null;

            // If not an article list page, return with redirect suggestion
            if (!$isArticleListPage) {
                return [
                    'success' => false,
                    'is_article_list_page' => false,
                    'page_type' => $pageType,
                    'page_type_reason' => $pageTypeReason,
                    'article_list_url' => $articleListUrl ? $this->resolveUrl($articleListUrl, $url) : null,
                    'error' => $articleListUrl
                        ? 'このページは論文一覧ページではありません．最新論文一覧ページへのリンクを検出しました．'
                        : 'このページは論文一覧ページではありません．最新論文一覧ページのURLを直接指定してください．',
                    'provider' => $provider,
                ];
            }

            // Validate selectors for article list pages
            if (empty($result['selectors']) || empty($result['selectors']['paper_container'])) {
                return [
                    'success' => false,
                    'is_article_list_page' => true,
                    'page_type' => $pageType,
                    'error' => 'ページ構造を特定できませんでした．',
                    'provider' => $provider,
                ];
            }

            return [
                'success' => true,
                'is_article_list_page' => true,
                'page_type' => $pageType,
                'page_type_reason' => $pageTypeReason,
                'selectors' => $result['selectors'] ?? [],
                'sample_papers' => $result['sample_papers'] ?? [],
                'provider' => $provider,
            ];
        } catch (\Exception $e) {
            Log::error('AI structure analysis failed', [
                'url' => $url,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build prompt for structure analysis (focus on selectors, not content)
     */
    private function buildStructureAnalysisPrompt(string $html, string $url): string
    {
        return <<<PROMPT
以下のHTMLを解析し，学術論文の最新一覧ページかどうかを判断してください．

【URL】
{$url}

【HTML】
{$html}

以下のJSON形式で回答してください：
{
  "is_article_list_page": true または false,
  "page_type": "ページの種類（article_list: 論文一覧, journal_home: 雑誌トップページ, article_detail: 個別論文ページ, search_results: 検索結果, other: その他）",
  "page_type_reason": "ページ種類を判断した理由（50文字以内）",
  "article_list_url": "最新論文一覧ページへのリンクURL（is_article_list_pageがfalseの場合に検出できれば記載，相対URLでも可）",
  "selectors": {
    "paper_container": "各論文を含むコンテナ要素のCSSセレクタ（例：.article-list > .article-item）",
    "title": "コンテナ内でのタイトル要素のCSSセレクタ（例：.article-title a）",
    "title_attr": "タイトルテキストの取得方法（textContent または href などの属性名）",
    "url": "論文URLを含む要素のCSSセレクタ（例：.article-title a）",
    "url_attr": "URLの取得属性（通常は href）",
    "authors": "著者要素のCSSセレクタ（なければnull）",
    "authors_attr": "著者テキストの取得方法（textContent または属性名）",
    "abstract": "概要要素のCSSセレクタ（なければnull）",
    "date": "公開日要素のCSSセレクタ（なければnull）",
    "date_format": "日付フォーマット（例：Y-m-d, Y年m月d日）",
    "doi": "DOI要素のCSSセレクタ（なければnull）",
    "doi_attr": "DOIの取得方法（textContent, href, data-doi など）",
    "doi_pattern": "DOI文字列から実際のDOIを抽出する正規表現パターン（例：10\\.\\d+/[^\\s]+）"
  },
  "base_url": "相対URLを絶対URLに変換するためのベースURL",
  "sample_papers": [
    {
      "title": "検出された論文タイトル（動作確認用，2-3件）",
      "url": "論文URL",
      "doi": "DOI（検出できた場合）"
    }
  ]
}

重要:
- is_article_list_page: 論文のタイトル，著者，概要などを含む最新論文一覧ページならtrue
- page_type: ページの実際の種類を正確に判定してください
- article_list_url: is_article_list_pageがfalseの場合，同じサイト内の最新論文一覧ページへのリンクを探してください（"Latest Articles"，"Recent"，"新着論文"，"最新号"などのリンク）
- selectorsはis_article_list_pageがtrueの場合のみ必須です
- CSSセレクタは具体的かつ正確に記述してください
- paper_containerは各論文が1つずつ含まれる最小の繰り返し要素を指定してください
- DOIは "10.XXXX/..." の形式で，リンクhref（https://doi.org/10.XXXX/...），data属性，またはテキストに含まれることがあります
- JSON形式のみで回答し，他のテキストは含めないでください
PROMPT;
    }

    /**
     * Parse page using saved selectors (no AI needed)
     */
    public function parsePageWithSelectors(string $html, array $selectors, string $baseUrl): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Convert CSS selector to XPath (simplified conversion)
        $containerXpath = $this->cssToXpath($selectors['paper_container'] ?? '');
        if (empty($containerXpath)) {
            Log::warning('Invalid container selector', ['selector' => $selectors['paper_container'] ?? '']);
            return ['success' => false, 'error' => 'Invalid container selector: ' . ($selectors['paper_container'] ?? 'empty')];
        }

        Log::debug('Parsing with selectors', [
            'container_css' => $selectors['paper_container'] ?? '',
            'container_xpath' => $containerXpath,
            'title_css' => $selectors['title'] ?? '',
        ]);

        $papers = [];
        $containers = $xpath->query($containerXpath);

        if ($containers === false || $containers->length === 0) {
            Log::warning('No paper containers found', [
                'xpath' => $containerXpath,
                'html_length' => strlen($html),
            ]);
            return ['success' => false, 'error' => 'No paper containers found with selector: ' . ($selectors['paper_container'] ?? '')];
        }

        Log::debug('Found containers', ['count' => $containers->length]);

        $skippedCount = 0;
        foreach ($containers as $container) {
            $paper = $this->extractPaperFromContainer($xpath, $container, $selectors, $baseUrl);
            if ($paper && !empty($paper['title'])) {
                $papers[] = $paper;
            } else {
                $skippedCount++;
            }
        }

        Log::debug('Parsing result', [
            'papers_found' => count($papers),
            'skipped' => $skippedCount,
        ]);

        return [
            'success' => true,
            'papers' => $papers,
            'containers_found' => $containers->length,
            'skipped' => $skippedCount,
        ];
    }

    /**
     * Extract paper data from a container element
     */
    private function extractPaperFromContainer(DOMXPath $xpath, \DOMNode $container, array $selectors, string $baseUrl): ?array
    {
        $paper = [
            'title' => null,
            'url' => null,
            'authors' => [],
            'abstract' => null,
            'published_date' => null,
            'doi' => null,
        ];

        // Extract title
        if (!empty($selectors['title'])) {
            $titleXpath = $this->cssToXpath($selectors['title'], true);
            $titleNodes = $xpath->query($titleXpath, $container);
            if ($titleNodes && $titleNodes->length > 0) {
                $titleNode = $titleNodes->item(0);
                $attr = $selectors['title_attr'] ?? 'textContent';
                $paper['title'] = $attr === 'textContent'
                    ? trim($titleNode->textContent)
                    : trim($titleNode->getAttribute($attr));
            }
        }

        // Extract URL
        if (!empty($selectors['url'])) {
            $urlXpath = $this->cssToXpath($selectors['url'], true);
            $urlNodes = $xpath->query($urlXpath, $container);
            if ($urlNodes && $urlNodes->length > 0) {
                $urlNode = $urlNodes->item(0);
                $attr = $selectors['url_attr'] ?? 'href';
                $url = trim($urlNode->getAttribute($attr));
                $paper['url'] = $this->resolveUrl($url, $baseUrl);
            }
        }

        // Extract authors
        if (!empty($selectors['authors'])) {
            $authorsXpath = $this->cssToXpath($selectors['authors'], true);
            $authorsNodes = $xpath->query($authorsXpath, $container);
            if ($authorsNodes && $authorsNodes->length > 0) {
                $authorsNode = $authorsNodes->item(0);
                $attr = $selectors['authors_attr'] ?? 'textContent';
                $authorsText = $attr === 'textContent'
                    ? trim($authorsNode->textContent)
                    : trim($authorsNode->getAttribute($attr));
                // Split authors by common delimiters
                $paper['authors'] = array_filter(array_map('trim', preg_split('/[,;，、]/', $authorsText)));
            }
        }

        // Extract abstract
        if (!empty($selectors['abstract'])) {
            $abstractXpath = $this->cssToXpath($selectors['abstract'], true);
            $abstractNodes = $xpath->query($abstractXpath, $container);
            if ($abstractNodes && $abstractNodes->length > 0) {
                $paper['abstract'] = trim($abstractNodes->item(0)->textContent);
            }
        }

        // Extract date
        if (!empty($selectors['date'])) {
            $dateXpath = $this->cssToXpath($selectors['date'], true);
            $dateNodes = $xpath->query($dateXpath, $container);
            if ($dateNodes && $dateNodes->length > 0) {
                $dateText = trim($dateNodes->item(0)->textContent);
                $paper['published_date'] = $this->parseDate($dateText);
            }
        }

        // Extract DOI
        if (!empty($selectors['doi'])) {
            $doiXpath = $this->cssToXpath($selectors['doi'], true);
            $doiNodes = $xpath->query($doiXpath, $container);
            if ($doiNodes && $doiNodes->length > 0) {
                $doiNode = $doiNodes->item(0);
                $attr = $selectors['doi_attr'] ?? 'textContent';
                $doiRaw = $attr === 'textContent'
                    ? trim($doiNode->textContent)
                    : trim($doiNode->getAttribute($attr));
                $paper['doi'] = $this->extractDoi($doiRaw, $selectors['doi_pattern'] ?? null);
            }
        }

        // Try to extract DOI from URL if not found via selector
        if (empty($paper['doi']) && !empty($paper['url'])) {
            $paper['doi'] = $this->extractDoiFromUrl($paper['url']);
        }

        return $paper;
    }

    /**
     * Extract DOI from raw string using pattern or default regex
     */
    private function extractDoi(string $raw, ?string $pattern = null): ?string
    {
        if (empty($raw)) {
            return null;
        }

        // Default DOI pattern: 10.XXXX/... (with various allowed characters)
        // Use # as delimiter to avoid conflicts with / in patterns
        $defaultPattern = '#10\.\d{4,}/[^\s"\'<>]+#';
        $usePattern = $pattern ? '#' . $pattern . '#' : $defaultPattern;

        if (preg_match($usePattern, $raw, $matches)) {
            // Clean up trailing punctuation
            $doi = rtrim($matches[0], '.,;:)');
            return $doi;
        }

        return null;
    }

    /**
     * Extract DOI from URL (e.g., https://doi.org/10.1234/...)
     */
    private function extractDoiFromUrl(string $url): ?string
    {
        // Check for doi.org URL
        if (preg_match('/(?:doi\.org|dx\.doi\.org)\/(10\.\d{4,}\/[^\s"\'<>]+)/i', $url, $matches)) {
            return rtrim($matches[1], '.,;:)');
        }

        return null;
    }

    /**
     * CSS selector to XPath converter
     * Handles: element, .class, #id, [attr], element.class, .class1.class2, parent > child, ancestor descendant
     */
    private function cssToXpath(string $css, bool $relative = false): string
    {
        if (empty($css)) {
            return '';
        }

        $css = trim($css);
        $prefix = $relative ? './' : '/';

        // Split by descendant combinator (space) and child combinator (>)
        // Preserve the combinator type for proper XPath generation
        $parts = preg_split('/(\s*>\s*|\s+)/', $css, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $xpath = $prefix;
        $isFirst = true;

        foreach ($parts as $part) {
            // Check combinators BEFORE trimming (space gets lost after trim)
            // Check if this is a child combinator (>)
            if ($part === '>' || preg_match('/^\s*>\s*$/', $part)) {
                $xpath .= '/';
                continue;
            }

            // Check if this is a descendant combinator (space only)
            // After splitting, a space-only part means descendant
            if (preg_match('/^\s+$/', $part)) {
                $xpath .= '//';
                continue;
            }

            // Now safe to trim and skip empty
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Convert the selector part to XPath
            $converted = $this->convertSelectorPart($part);

            if ($isFirst) {
                $xpath .= '/' . $converted;
                $isFirst = false;
            } else {
                $xpath .= $converted;
            }
        }

        return $xpath;
    }

    /**
     * Convert a single CSS selector part (element, .class, #id, etc.) to XPath
     */
    private function convertSelectorPart(string $selector): string
    {
        $element = '*';
        $predicates = [];

        // Extract element name if present at the start
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9]*)(\.|\#|\[|$)/', $selector, $m)) {
            $element = $m[1];
            $selector = substr($selector, strlen($m[1]));
        }

        // Extract all classes
        preg_match_all('/\.([a-zA-Z0-9_-]+)/', $selector, $classes);
        foreach ($classes[1] as $class) {
            $predicates[] = 'contains(@class, "' . $class . '")';
        }

        // Extract all IDs
        preg_match_all('/#([a-zA-Z0-9_-]+)/', $selector, $ids);
        foreach ($ids[1] as $id) {
            $predicates[] = '@id="' . $id . '"';
        }

        // Extract attribute selectors [attr] or [attr=value]
        preg_match_all('/\[([a-zA-Z0-9_-]+)(?:=(["\']?)([^"\'\]]+)\2)?\]/', $selector, $attrs, PREG_SET_ORDER);
        foreach ($attrs as $attr) {
            if (isset($attr[3])) {
                $predicates[] = '@' . $attr[1] . '="' . $attr[3] . '"';
            } else {
                $predicates[] = '@' . $attr[1];
            }
        }

        $result = $element;
        if (!empty($predicates)) {
            $result .= '[' . implode(' and ', $predicates) . ']';
        }

        return $result;
    }

    /**
     * Resolve relative URL to absolute
     */
    private function resolveUrl(string $url, string $baseUrl): string
    {
        if (empty($url)) {
            return '';
        }

        // Already absolute
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';

        if (strpos($url, '//') === 0) {
            return $scheme . ':' . $url;
        }

        if (strpos($url, '/') === 0) {
            return $scheme . '://' . $host . $url;
        }

        // Relative path
        $basePath = $base['path'] ?? '/';
        $basePath = dirname($basePath);
        return $scheme . '://' . $host . $basePath . '/' . $url;
    }

    /**
     * Parse various date formats from text (may contain additional text around the date)
     */
    private function parseDate(string $dateText): ?string
    {
        if (empty($dateText)) {
            return null;
        }

        // First, try to extract date patterns from the text using regex
        $patterns = [
            // YYYY/MM/DD or YYYY-MM-DD (common formats)
            '/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/' => function ($m) {
                return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            },
            // YYYY年MM月DD日 (Japanese format)
            '/(\d{4})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日/' => function ($m) {
                return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            },
            // DD Month YYYY or Month DD, YYYY (English formats)
            '/(\d{1,2})\s+(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{4})/i' => function ($m) {
                $timestamp = strtotime("{$m[2]} {$m[1]}, {$m[3]}");
                return $timestamp ? date('Y-m-d', $timestamp) : null;
            },
            '/(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{1,2}),?\s+(\d{4})/i' => function ($m) {
                $timestamp = strtotime("{$m[1]} {$m[2]}, {$m[3]}");
                return $timestamp ? date('Y-m-d', $timestamp) : null;
            },
        ];

        foreach ($patterns as $pattern => $formatter) {
            if (preg_match($pattern, $dateText, $matches)) {
                $result = $formatter($matches);
                if ($result) {
                    return $result;
                }
            }
        }

        // Try strtotime as fallback (works for many English date formats)
        $timestamp = strtotime($dateText);
        if ($timestamp && $timestamp > strtotime('1990-01-01')) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    /**
     * Check if model uses new OpenAI API format (max_completion_tokens)
     */
    private function isNewOpenAIModel(string $model): bool
    {
        return strpos($model, 'gpt-4o') !== false
            || strpos($model, 'gpt-4-turbo') !== false
            || preg_match('/^o[0-9]/', $model) === 1;
    }

    /**
     * Call Claude API for structure analysis
     */
    private function callClaudeForAnalysis(string $prompt): array
    {
        // Use user's API key if available, otherwise fall back to admin/system config
        $apiKey = null;
        if ($this->user && $this->user->hasClaudeApiKey()) {
            $apiKey = $this->user->claude_api_key;
        } elseif (config('services.ai.admin_claude_api_key')) {
            $apiKey = config('services.ai.admin_claude_api_key');
        } else {
            $apiKey = config('services.ai.claude_api_key');
        }

        if (!$apiKey) {
            throw new \Exception('Claude API key is not configured');
        }

        $model = $this->user->preferred_claude_model ?? config('services.ai.claude_default_model', 'claude-sonnet-4-20250514');

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(self::CLAUDE_API_URL, [
            'model' => $model,
            'max_tokens' => 4000,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception('Claude API request failed: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['content'][0]['text'] ?? '';

        return $this->parseJsonResponse($content);
    }

    /**
     * Call OpenAI API for structure analysis
     */
    private function callOpenAIForAnalysis(string $prompt): array
    {
        // Use user's API key if available, otherwise fall back to admin/system config
        $apiKey = null;
        if ($this->user && $this->user->hasOpenaiApiKey()) {
            $apiKey = $this->user->openai_api_key;
        } elseif (config('services.ai.admin_openai_api_key')) {
            $apiKey = config('services.ai.admin_openai_api_key');
        } else {
            $apiKey = config('services.ai.openai_api_key');
        }

        if (!$apiKey) {
            throw new \Exception('OpenAI API key is not configured');
        }

        $model = $this->user->preferred_openai_model ?? config('services.ai.openai_default_model', 'gpt-4o');

        // For page structure analysis, we need a model with large context
        // gpt-3.5-turbo only has 16k context which is often not enough
        // Automatically upgrade to gpt-4o-mini (128k context) for this task
        if ($model === 'gpt-3.5-turbo') {
            $model = 'gpt-4o-mini';
            Log::info('Upgraded model to gpt-4o-mini for RSS page analysis (gpt-3.5-turbo context too small)');
        }

        $requestBody = [
            'model' => $model,
            'temperature' => 0.1,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an expert at analyzing HTML page structures and identifying CSS selectors for web scraping. Always respond in valid JSON format.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if ($this->isNewOpenAIModel($model)) {
            $requestBody['max_completion_tokens'] = 4000;
        } else {
            $requestBody['max_tokens'] = 4000;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(self::OPENAI_API_URL, $requestBody);

        if (!$response->successful()) {
            throw new \Exception('OpenAI API request failed: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? '';

        return $this->parseJsonResponse($content);
    }

    /**
     * Parse JSON response from AI
     */
    private function parseJsonResponse(string $content): array
    {
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        $content = trim($content);

        try {
            $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($parsed) ? $parsed : [];
        } catch (\JsonException $e) {
            Log::warning('Failed to parse AI response as JSON', [
                'content' => $content,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Extract papers from HTML using AI
     * AI handles all data extraction and normalization
     */
    public function extractPapersWithAi(string $html, string $url): array
    {
        $provider = $this->getPreferredProvider();
        if (!$provider) {
            return [
                'success' => false,
                'error' => 'No AI API key configured',
            ];
        }

        $prompt = $this->buildPaperExtractionPrompt($html, $url);

        try {
            if ($provider === 'claude') {
                $result = $this->callClaudeForExtraction($prompt);
            } else {
                $result = $this->callOpenAIForExtraction($prompt);
            }

            $papers = $result['papers'] ?? [];

            if (empty($papers)) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'No papers extracted from page',
                    'provider' => $provider,
                ];
            }

            // Normalize paper data
            $normalizedPapers = array_map(function ($paper) use ($url) {
                return [
                    'title' => $paper['title'] ?? null,
                    'url' => isset($paper['url']) ? $this->resolveUrl($paper['url'], $url) : null,
                    'authors' => $paper['authors'] ?? [],
                    'abstract' => $paper['abstract'] ?? null,
                    'published_date' => $paper['published_date'] ?? null,
                    'doi' => $paper['doi'] ?? null,
                ];
            }, $papers);

            // Filter out papers without titles
            $normalizedPapers = array_filter($normalizedPapers, fn($p) => !empty($p['title']));

            return [
                'success' => true,
                'papers' => array_values($normalizedPapers),
                'papers_count' => count($normalizedPapers),
                'provider' => $provider,
            ];
        } catch (\Exception $e) {
            Log::error('AI paper extraction failed', [
                'url' => $url,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => $provider,
            ];
        }
    }

    /**
     * Build prompt for paper extraction
     */
    private function buildPaperExtractionPrompt(string $html, string $url): string
    {
        return <<<PROMPT
以下のHTMLから学術論文の一覧を抽出し、JSON形式で返してください。

【URL】
{$url}

【HTML】
{$html}

以下のJSON形式で回答してください：
{
  "papers": [
    {
      "title": "論文タイトル（必須）",
      "url": "論文の詳細ページURL（相対URLでも可）",
      "authors": ["著者1", "著者2"],
      "abstract": "論文の概要・要旨（あれば）",
      "published_date": "公開日（YYYY-MM-DD形式に正規化）",
      "doi": "DOI（10.XXXX/... 形式、あれば）"
    }
  ],
  "error": "エラーがあれば記載"
}

重要な指示：
- 論文一覧ページから各論文の情報を抽出してください
- 日付は必ず YYYY-MM-DD 形式に変換してください（例：2025年10月1日 → 2025-10-01）
- DOIは "10." で始まる部分のみを抽出してください（URLの場合は10.以降）
- 著者名は配列で返してください
- 論文以外のコンテンツ（広告、ナビゲーション等）は除外してください
- 抽出できた論文のみを含めてください（推測で情報を追加しないでください）
- JSON形式のみで回答し、他のテキストは含めないでください
PROMPT;
    }

    /**
     * Call Claude API for paper extraction
     */
    private function callClaudeForExtraction(string $prompt): array
    {
        $apiKey = null;
        if ($this->user && $this->user->hasClaudeApiKey()) {
            $apiKey = $this->user->claude_api_key;
        } elseif (config('services.ai.admin_claude_api_key')) {
            $apiKey = config('services.ai.admin_claude_api_key');
        } else {
            $apiKey = config('services.ai.claude_api_key');
        }

        if (!$apiKey) {
            throw new \Exception('Claude API key is not configured');
        }

        $model = $this->user->preferred_claude_model ?? config('services.ai.claude_default_model', 'claude-sonnet-4-20250514');

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(self::CLAUDE_API_URL, [
            'model' => $model,
            'max_tokens' => 8000,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception('Claude API request failed: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['content'][0]['text'] ?? '';

        return $this->parseJsonResponse($content);
    }

    /**
     * Call OpenAI API for paper extraction
     */
    private function callOpenAIForExtraction(string $prompt): array
    {
        $apiKey = null;
        if ($this->user && $this->user->hasOpenaiApiKey()) {
            $apiKey = $this->user->openai_api_key;
        } elseif (config('services.ai.admin_openai_api_key')) {
            $apiKey = config('services.ai.admin_openai_api_key');
        } else {
            $apiKey = config('services.ai.openai_api_key');
        }

        if (!$apiKey) {
            throw new \Exception('OpenAI API key is not configured');
        }

        $model = $this->user->preferred_openai_model ?? config('services.ai.openai_default_model', 'gpt-4o');

        // Automatically upgrade to gpt-4o-mini for larger context
        if ($model === 'gpt-3.5-turbo') {
            $model = 'gpt-4o-mini';
            Log::info('Upgraded model to gpt-4o-mini for paper extraction (gpt-3.5-turbo context too small)');
        }

        $requestBody = [
            'model' => $model,
            'temperature' => 0.1,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an expert at extracting structured data from academic journal web pages. Always respond in valid JSON format.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if ($this->isNewOpenAIModel($model)) {
            $requestBody['max_completion_tokens'] = 8000;
        } else {
            $requestBody['max_tokens'] = 8000;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(self::OPENAI_API_URL, $requestBody);

        if (!$response->successful()) {
            throw new \Exception('OpenAI API request failed: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? '';

        return $this->parseJsonResponse($content);
    }

    /**
     * Fetch papers using saved selectors (no AI needed)
     * Returns parsed papers array
     */
    public function fetchPapers(Journal $journal): array
    {
        $feed = GeneratedFeed::where('journal_id', $journal->id)->first();

        if (!$feed) {
            return [
                'success' => false,
                'error' => 'No feed configuration found. Please run initial setup.',
            ];
        }

        $selectors = $feed->extraction_config['selectors'] ?? null;
        $baseUrl = $feed->extraction_config['base_url'] ?? $journal->rss_url;

        if (empty($selectors) || empty($selectors['paper_container'])) {
            return [
                'success' => false,
                'error' => 'No selectors configured. Please reanalyze page structure.',
            ];
        }

        // Fetch page (raw HTML, no cleaning needed for selector parsing)
        $pageResult = $this->fetchWebPage($journal->rss_url, false);
        if (!$pageResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to fetch page: ' . ($pageResult['error'] ?? 'Unknown error'),
            ];
        }

        // Parse using selectors
        $parseResult = $this->parsePageWithSelectors($pageResult['html'], $selectors, $baseUrl);
        if (!$parseResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to parse page: ' . ($parseResult['error'] ?? 'Unknown error'),
            ];
        }

        $papers = $parseResult['papers'] ?? [];
        if (empty($papers)) {
            $containersFound = $parseResult['containers_found'] ?? 0;
            $skipped = $parseResult['skipped'] ?? 0;
            $errorDetail = $containersFound > 0
                ? "Found {$containersFound} containers but could not extract titles from any ({$skipped} skipped). Check title selector."
                : 'No paper containers found. Page structure may have changed.';
            return [
                'success' => false,
                'error' => $errorDetail,
                'debug' => [
                    'containers_found' => $containersFound,
                    'skipped' => $skipped,
                    'selectors' => $selectors,
                ],
            ];
        }

        // Update feed status
        $feed->update([
            'generation_status' => 'success',
            'last_generated_at' => now(),
            'error_message' => null,
        ]);

        return [
            'success' => true,
            'papers' => $papers,
            'papers_count' => count($papers),
        ];
    }

    /**
     * Initial feed setup for AI-generated journal
     * Creates GeneratedFeed record and verifies page can be fetched
     * Actual paper extraction is done by RssFetcherService::fetchJournal
     */
    public function setupFeed(Journal $journal, User $user): array
    {
        $this->setUser($user);

        if (!$this->hasApiKey()) {
            return [
                'success' => false,
                'error' => 'No AI API key configured. Please set your API key in Settings.',
            ];
        }

        // Get or create GeneratedFeed record
        $feed = GeneratedFeed::firstOrNew(['journal_id' => $journal->id]);
        $feed->user_id = $user->id;
        $feed->source_url = $journal->rss_url;
        $feed->ai_provider = $this->getPreferredProvider();
        $feed->ai_model = $feed->ai_provider === 'claude'
            ? ($user->preferred_claude_model ?? config('services.ai.claude_default_model'))
            : ($user->preferred_openai_model ?? config('services.ai.openai_default_model'));
        $feed->generation_status = 'pending';
        $feed->save();

        // Verify page can be fetched
        $pageResult = $this->fetchWebPage($journal->rss_url, true);
        if (!$pageResult['success']) {
            $feed->markAsError('Failed to fetch page: ' . ($pageResult['error'] ?? 'Unknown error'));
            return [
                'success' => false,
                'error' => $pageResult['error'],
            ];
        }

        // Save basic config (no selectors needed - AI extracts directly)
        $feed->update([
            'extraction_config' => [
                'base_url' => $journal->rss_url,
                'setup_at' => now()->toISOString(),
                'ai_provider' => $this->getPreferredProvider(),
            ],
            'generation_status' => 'pending',
            'error_message' => null,
        ]);

        return [
            'success' => true,
            'message' => 'Feed setup complete. Ready for AI extraction.',
            'provider' => $this->getPreferredProvider(),
        ];
    }

    /**
     * Analyze and fetch papers - uses selectors if available, otherwise runs AI setup
     */
    public function analyzeAndFetchPapers(Journal $journal, User $user): array
    {
        $feed = GeneratedFeed::where('journal_id', $journal->id)->first();

        // If we have valid selectors, use them
        if ($feed && !empty($feed->extraction_config['selectors'])) {
            return $this->fetchPapers($journal);
        }

        // Otherwise, run initial AI setup
        return $this->setupFeed($journal, $user);
    }

    /**
     * Force reanalyze page structure with AI
     */
    public function reanalyzeStructure(Journal $journal, User $user): array
    {
        // Delete existing config to force full re-setup
        $feed = GeneratedFeed::where('journal_id', $journal->id)->first();
        if ($feed) {
            $feed->update(['extraction_config' => null]);
        }

        return $this->setupFeed($journal, $user);
    }

    /**
     * Test page analysis without saving (for preview)
     */
    public function testPageAnalysis(string $url, User $user): array
    {
        $this->setUser($user);

        if (!$this->hasApiKey()) {
            return [
                'success' => false,
                'error' => 'No AI API key configured. Please set your API key in Settings.',
            ];
        }

        // Fetch and analyze
        $pageResult = $this->fetchWebPage($url, true);
        if (!$pageResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to fetch page: ' . ($pageResult['error'] ?? 'Unknown error'),
            ];
        }

        $analysisResult = $this->analyzePageStructure($pageResult['html'], $url);

        // Return analysis result with page type info even on failure
        $response = [
            'success' => $analysisResult['success'],
            'is_article_list_page' => $analysisResult['is_article_list_page'] ?? null,
            'page_type' => $analysisResult['page_type'] ?? null,
            'page_type_reason' => $analysisResult['page_type_reason'] ?? null,
            'provider' => $analysisResult['provider'] ?? null,
            'page_size' => [
                'original' => $pageResult['original_size'],
                'cleaned' => $pageResult['cleaned_size'],
            ],
        ];

        if (!$analysisResult['success']) {
            $response['error'] = $analysisResult['error'] ?? 'AI analysis failed';
            // Include redirect suggestion if available
            if (!empty($analysisResult['article_list_url'])) {
                $response['article_list_url'] = $analysisResult['article_list_url'];
            }
            return $response;
        }

        $response['selectors'] = $analysisResult['selectors'] ?? [];
        $response['sample_papers'] = $analysisResult['sample_papers'] ?? [];

        return $response;
    }

    /**
     * Test page analysis with automatic redirect to article list page
     * If the initial page is not an article list, it will try the suggested URL
     */
    public function testPageAnalysisWithRedirect(string $url, User $user, int $maxRedirects = 2): array
    {
        $attempts = 0;
        $currentUrl = $url;
        $redirectHistory = [];

        while ($attempts < $maxRedirects) {
            $attempts++;
            $result = $this->testPageAnalysis($currentUrl, $user);

            // If successful or no redirect suggestion, return as-is
            if ($result['success'] || empty($result['article_list_url'])) {
                $result['redirect_history'] = $redirectHistory;
                $result['final_url'] = $currentUrl;
                return $result;
            }

            // Follow the redirect
            $redirectHistory[] = [
                'from' => $currentUrl,
                'to' => $result['article_list_url'],
                'page_type' => $result['page_type'],
            ];
            $currentUrl = $result['article_list_url'];
        }

        // Max redirects reached
        $result = $this->testPageAnalysis($currentUrl, $user);
        $result['redirect_history'] = $redirectHistory;
        $result['final_url'] = $currentUrl;
        return $result;
    }
}
