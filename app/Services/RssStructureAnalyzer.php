<?php

namespace App\Services;

use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimplePie\SimplePie;

/**
 * RSSフィード構造をAIで解析し，抽出ルールを生成するサービス
 */
class RssStructureAnalyzer
{
    private const CLAUDE_API_URL = 'https://api.anthropic.com/v1/messages';
    private const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';

    private ?User $user = null;

    /**
     * Set the user context for API key retrieval
     */
    public function setUser(User $user): self
    {
        $this->user = User::find($user->id);
        return $this;
    }

    /**
     * RSSフィードを解析し，抽出ルールを生成
     *
     * @param Journal $journal
     * @param string|null $provider
     * @param string|null $model
     * @return array ['success' => bool, 'config' => array|null, 'error' => string|null]
     */
    public function analyzeRssFeed(Journal $journal, ?string $provider = null, ?string $model = null): array
    {
        try {
            // 1. RSSサンプルを取得
            $sampleResult = $this->fetchRssSample($journal->rss_url);
            if (!$sampleResult['success']) {
                return [
                    'success' => false,
                    'config' => null,
                    'error' => $sampleResult['error'] ?? 'Failed to fetch RSS sample',
                ];
            }

            // 2. AIで構造解析
            $analysisResult = $this->analyzeWithAi(
                $sampleResult['raw_xml'],
                $sampleResult['sample_items'],
                $provider,
                $model
            );

            if (!$analysisResult['success']) {
                return [
                    'success' => false,
                    'config' => null,
                    'error' => $analysisResult['error'] ?? 'AI analysis failed',
                ];
            }

            return [
                'success' => true,
                'config' => $analysisResult['config'],
                'provider' => $analysisResult['provider'] ?? null,
                'model' => $analysisResult['model'] ?? null,
                'error' => null,
            ];

        } catch (\Exception $e) {
            Log::error('RSS structure analysis failed', [
                'journal_id' => $journal->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'config' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * RSSサンプルを取得（生XMLも含む）
     */
    private function fetchRssSample(string $rssUrl): array
    {
        try {
            // まずHTTPで生のXMLを取得
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SurveyMate/2.0; Academic Research)',
                    'Accept' => 'application/rss+xml, application/xml, text/xml, */*',
                ])
                ->get($rssUrl);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => "HTTP error: {$response->status()}",
                ];
            }

            $rawXml = $response->body();

            // SimplePieでパースしてサンプルアイテムを取得
            $feed = new SimplePie();
            $feed->set_raw_data($rawXml);
            $feed->enable_cache(false);
            $feed->init();

            if ($feed->error()) {
                return [
                    'success' => false,
                    'error' => "RSS parse error: {$feed->error()}",
                ];
            }

            // 最初の5件を取得
            $items = $feed->get_items(0, 5);
            $sampleItems = [];

            foreach ($items as $item) {
                $sampleItems[] = [
                    'title' => $item->get_title(),
                    'link' => $item->get_link(),
                    'date' => $item->get_date('Y-m-d'),
                    'author' => $item->get_author() ? $item->get_author()->get_name() : null,
                    'description' => mb_substr($item->get_description() ?? '', 0, 500),
                ];
            }

            // XMLからサンプルアイテムのXMLを抽出（最初の3件）
            $sampleXml = $this->extractSampleItemsXml($rawXml, 3);

            return [
                'success' => true,
                'raw_xml' => $sampleXml,
                'sample_items' => $sampleItems,
                'feed_type' => $feed->get_type(),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * XMLから最初のN件のアイテムを抽出
     */
    private function extractSampleItemsXml(string $xml, int $count = 3): string
    {
        try {
            // XMLをDOMでパース
            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = false;
            @$dom->loadXML($xml);

            // RSS 2.0の場合: channel/item
            $items = $dom->getElementsByTagName('item');
            if ($items->length > 0) {
                return $this->extractItemsFromNodeList($dom, $items, $count, 'rss2.0');
            }

            // Atomの場合: feed/entry
            $entries = $dom->getElementsByTagName('entry');
            if ($entries->length > 0) {
                return $this->extractItemsFromNodeList($dom, $entries, $count, 'atom');
            }

            // RDF/RSS 1.0の場合
            $rdfItems = $dom->getElementsByTagNameNS('http://purl.org/rss/1.0/', 'item');
            if ($rdfItems->length > 0) {
                return $this->extractItemsFromNodeList($dom, $rdfItems, $count, 'rdf');
            }

            // フォールバック: XMLの最初の部分を返す
            return mb_substr($xml, 0, 10000);

        } catch (\Exception $e) {
            Log::warning('Failed to extract sample XML', ['error' => $e->getMessage()]);
            return mb_substr($xml, 0, 10000);
        }
    }

    /**
     * NodeListからサンプルアイテムのXMLを生成
     */
    private function extractItemsFromNodeList(\DOMDocument $dom, \DOMNodeList $items, int $count, string $feedType): string
    {
        $result = "<!-- Feed type: {$feedType}, Sample items: " . min($count, $items->length) . " of {$items->length} -->\n\n";

        for ($i = 0; $i < min($count, $items->length); $i++) {
            $item = $items->item($i);
            $result .= "<!-- Item " . ($i + 1) . " -->\n";
            $result .= $dom->saveXML($item) . "\n\n";
        }

        return $result;
    }

    /**
     * AIで構造解析を実行
     */
    private function analyzeWithAi(string $xmlSample, array $sampleItems, ?string $provider = null, ?string $model = null): array
    {
        $provider = $provider ?? $this->determineProvider();

        $prompt = $this->buildAnalysisPrompt($xmlSample, $sampleItems);

        try {
            switch ($provider) {
                case 'claude':
                    $result = $this->callClaude($prompt, $model);
                    break;
                default:
                    $result = $this->callOpenAI($prompt, $model);
                    break;
            }

            // JSONレスポンスをパース
            $config = $this->parseAiResponse($result['content']);

            if (!$config) {
                return [
                    'success' => false,
                    'error' => 'Failed to parse AI response as valid extraction config',
                ];
            }

            return [
                'success' => true,
                'config' => $config,
                'provider' => $result['provider'],
                'model' => $result['model'],
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 使用可能なプロバイダーを判定
     */
    private function determineProvider(): string
    {
        if ($this->user) {
            if ($this->user->preferred_ai_provider) {
                return $this->user->preferred_ai_provider;
            }
            if ($this->user->hasEffectiveClaudeApiKey()) {
                return 'claude';
            }
            if ($this->user->hasEffectiveOpenaiApiKey()) {
                return 'openai';
            }
        }

        return config('services.ai.provider', 'openai');
    }

    /**
     * 解析用プロンプトを構築
     */
    private function buildAnalysisPrompt(string $xmlSample, array $sampleItems): string
    {
        $sampleItemsJson = json_encode($sampleItems, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
以下のRSSフィードのXMLサンプルを分析し，論文情報を抽出するためのルールをJSON形式で出力してください．

【抽出対象フィールド】
1. title: 論文タイトル（必須）
2. published_date: 出版日（YYYY-MM-DD形式に変換可能なもの）
3. authors: 著者名一覧（配列）
4. doi: DOI（10.xxxx/... 形式）
5. abstract: 概要・アブストラクト（あれば）
6. url: 論文ページURL

【XMLサンプル（最初の3件）】
```xml
{$xmlSample}
```

【SimplePieで解析した結果（参考）】
```json
{$sampleItemsJson}
```

【出力形式】
以下のJSON形式で出力してください:
```json
{
  "feed_type": "rss2.0|atom|rdf",
  "item_element": "item|entry",
  "fields": {
    "title": {
      "element": "title",
      "namespace": null,
      "attribute": null
    },
    "published_date": {
      "element": "pubDate|published|dc:date",
      "namespace": "http://purl.org/dc/elements/1.1/",
      "date_format": "RFC2822|ISO8601|custom"
    },
    "authors": {
      "element": "dc:creator|author",
      "namespace": "http://purl.org/dc/elements/1.1/",
      "multiple": true,
      "child_element": "name"
    },
    "doi": {
      "element": "dc:identifier|link",
      "namespace": "http://purl.org/dc/elements/1.1/",
      "attribute": "href",
      "pattern": "10\\\\.\\\\d{4,}/[^\\\\s]+"
    },
    "abstract": {
      "element": "description|summary|dc:description",
      "namespace": null,
      "fallback_from_summary": null
    },
    "url": {
      "element": "link|id",
      "namespace": null,
      "attribute": "href"
    }
  },
  "namespaces": {
    "dc": "http://purl.org/dc/elements/1.1/",
    "prism": "http://prismstandard.org/namespaces/basic/2.0/",
    "atom": "http://www.w3.org/2005/Atom"
  },
  "summary_parsing": {
    "enabled": false,
    "source_element": "description|summary",
    "patterns": {
      "authors": null,
      "doi": null,
      "abstract": null
    }
  }
}
```

【特別な注意事項】
- 各フィールドの要素名とネームスペースを特定してください
- 名前空間を使用している場合は「namespaces」に明記してください
- 複数の候補がある場合は「|」で区切って優先順位付きで列挙してください
- 該当フィールドが存在しない場合はnullを返してください

【summaryタグ内の平文解析について】
<summary>や<description>タグ内に，著者名・DOI・概要などが平文で混在している場合があります．
例:
  <summary>
  Authors: John Doe, Jane Smith
  DOI: 10.1234/example
  This paper presents...
  </summary>

このような場合は:
1. summary_parsing.enabled を true に設定
2. patterns に正規表現パターンを設定（例: "Authors?:\\s*(.+)" で著者を抽出）
3. 適切な source_element を設定

重要:
- JSON形式のみで回答し，他のテキストは含めないでください
- コードブロック（```）は使用せず，純粋なJSONのみを出力してください
PROMPT;
    }

    /**
     * Claude APIを呼び出し
     */
    private function callClaude(string $prompt, ?string $model = null): array
    {
        $apiKey = $this->user?->getEffectiveClaudeApiKey();

        if (!$apiKey) {
            throw new \Exception('Claude API key is not configured.');
        }

        $model = $model ?? config('services.ai.claude_default_model', 'claude-sonnet-4-20250514');

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(self::CLAUDE_API_URL, [
            'model' => $model,
            'max_tokens' => 2000,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if (!$response->successful()) {
            Log::error('Claude API error (RSS analysis)', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Claude API request failed: ' . $response->body());
        }

        $data = $response->json();

        return [
            'content' => $data['content'][0]['text'] ?? '',
            'provider' => 'claude',
            'model' => $model,
        ];
    }

    /**
     * OpenAI APIを呼び出し
     */
    private function callOpenAI(string $prompt, ?string $model = null): array
    {
        $apiKey = $this->user?->getEffectiveOpenaiApiKey();

        if (!$apiKey) {
            throw new \Exception('OpenAI API key is not configured.');
        }

        $model = $model ?? config('services.ai.openai_default_model', 'gpt-4o');

        $requestBody = [
            'model' => $model,
            'temperature' => 0.1,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an XML/RSS feed structure analysis expert. Always respond with valid JSON only, no additional text or code blocks.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        // Use max_completion_tokens for newer models
        if (strpos($model, 'gpt-4o') !== false || strpos($model, 'gpt-4-turbo') !== false) {
            $requestBody['max_completion_tokens'] = 2000;
        } else {
            $requestBody['max_tokens'] = 2000;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(self::OPENAI_API_URL, $requestBody);

        if (!$response->successful()) {
            Log::error('OpenAI API error (RSS analysis)', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('OpenAI API request failed: ' . $response->body());
        }

        $data = $response->json();

        return [
            'content' => $data['choices'][0]['message']['content'] ?? '',
            'provider' => 'openai',
            'model' => $model,
        ];
    }

    /**
     * AIレスポンスをパースして抽出設定に変換
     */
    private function parseAiResponse(string $content): ?array
    {
        // Markdownコードブロックを除去
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        $content = trim($content);

        try {
            $config = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            // 必須フィールドの検証
            if (!isset($config['fields']) || !is_array($config['fields'])) {
                Log::warning('Invalid RSS extraction config: missing fields', ['content' => $content]);
                return null;
            }

            // titleフィールドは必須
            if (!isset($config['fields']['title'])) {
                Log::warning('Invalid RSS extraction config: missing title field', ['content' => $content]);
                return null;
            }

            // デフォルト値を補完
            $config['feed_type'] = $config['feed_type'] ?? 'rss2.0';
            $config['item_element'] = $config['item_element'] ?? 'item';
            $config['namespaces'] = $config['namespaces'] ?? [];
            $config['summary_parsing'] = $config['summary_parsing'] ?? ['enabled' => false];

            return $config;

        } catch (\JsonException $e) {
            Log::warning('Failed to parse AI response as JSON', [
                'content' => $content,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
