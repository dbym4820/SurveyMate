<?php

namespace App\Services;

use App\Models\Paper;
use App\Models\Summary;
use App\Models\SummaryMessage;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiSummaryService
{
    private const CLAUDE_API_URL = 'https://api.anthropic.com/v1/messages';
    private const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';

    /** @var User|null */
    private $user;

    /** @var FullTextFetcherService|null */
    private $fullTextFetcher;

    /**
     * Set the user context for API key retrieval
     * Always refresh from database to ensure we have the latest settings
     *
     * @param User $user
     * @return self
     */
    public function setUser(User $user): self
    {
        // データベースから最新のユーザー情報を取得して，調査観点や要約テンプレートを確実に反映
        $this->user = User::find($user->id);
        return $this;
    }

    /**
     * Get available providers for the current user
     *
     * @return array
     */
    public function getAvailableProviders(): array
    {
        $providers = [];

        // Check user's effective API keys (includes admin env fallback for admin users)
        $hasClaudeKey = $this->user && $this->user->hasEffectiveClaudeApiKey();
        $hasOpenaiKey = $this->user && $this->user->hasEffectiveOpenaiApiKey();

        // OpenAI first (default provider)
        if ($hasOpenaiKey) {
            $openaiModels = $this->parseModelList(
                config('services.ai.openai_available_models', 'gpt-4o,gpt-4o-mini,gpt-4-turbo,gpt-3.5-turbo')
            );
            $providers[] = [
                'id' => 'openai',
                'name' => 'OpenAI',
                'models' => $openaiModels,
                'default_model' => config('services.ai.openai_default_model', 'gpt-4o'),
                'user_key' => $this->user && $this->user->hasOpenaiApiKey(),
                'from_env' => $this->user && $this->user->isOpenaiApiKeyFromEnv(),
            ];
        }

        if ($hasClaudeKey) {
            $claudeModels = $this->parseModelList(
                config('services.ai.claude_available_models', 'claude-sonnet-4-20250514,claude-opus-4-20250514,claude-3-5-haiku-20241022')
            );
            $providers[] = [
                'id' => 'claude',
                'name' => 'Claude',
                'models' => $claudeModels,
                'default_model' => config('services.ai.claude_default_model', 'claude-sonnet-4-20250514'),
                'user_key' => $this->user && $this->user->hasClaudeApiKey(),
                'from_env' => $this->user && $this->user->isClaudeApiKeyFromEnv(),
            ];
        }

        return $providers;
    }

    /**
     * Get FullTextFetcherService instance (lazy loading)
     */
    private function getFullTextFetcher(): FullTextFetcherService
    {
        if ($this->fullTextFetcher === null) {
            $this->fullTextFetcher = app(FullTextFetcherService::class);
        }
        return $this->fullTextFetcher;
    }

    /**
     * データソースの優先順位に従って要約用コンテンツを準備
     *
     * 優先順位:
     * 1. ローカルPDF（実際にダウンロード済みのPDF）
     * 2. DOIページから本文を取得
     * 3. full_text（既に取得済みの本文）
     * 4. リモートPDF URL（PDFがリモートにのみある場合）
     * 5. アブストラクト
     * 6. タイトル+利用可能なメタデータ
     *
     * @param Paper $paper
     * @return array ['source' => string, 'content' => string|null, 'pdf_url' => string|null, 'pdf_available' => bool]
     */
    private function prepareContentForSummary(Paper $paper): array
    {
        // 1. ローカルPDF（実際にダウンロード済み）があるかチェック
        if ($paper->hasLocalPdf()) {
            return [
                'source' => 'pdf',
                'content' => null,
                'pdf_url' => $paper->pdf_url,
                'pdf_available' => true,
            ];
        }

        // 2. DOIがあればDOIページから本文取得を試みる（原則DOI先を参照）
        if (!empty($paper->doi)) {
            Log::info("Attempting to fetch full text from DOI for paper {$paper->id}");

            try {
                $fetchResult = $this->getFullTextFetcher()->fetchFullText($paper);

                if ($fetchResult['success']) {
                    // 取得成功した場合，論文レコードを更新
                    $updateData = [
                        'full_text_source' => $fetchResult['source'],
                        'full_text_fetched_at' => now(),
                    ];

                    if ($fetchResult['text']) {
                        $updateData['full_text'] = $fetchResult['text'];
                    }
                    if ($fetchResult['pdf_url']) {
                        $updateData['pdf_url'] = $fetchResult['pdf_url'];
                    }
                    if ($fetchResult['pdf_path']) {
                        $updateData['pdf_path'] = $fetchResult['pdf_path'];
                    }

                    $paper->update($updateData);
                    $paper->refresh();

                    // PDFが取得できた場合
                    if ($fetchResult['pdf_url'] || $fetchResult['pdf_path']) {
                        return [
                            'source' => 'pdf_fetched',
                            'content' => $fetchResult['text'],
                            'pdf_url' => $fetchResult['pdf_url'],
                            'pdf_available' => true,
                        ];
                    }

                    // テキストのみ取得できた場合
                    if ($fetchResult['text']) {
                        return [
                            'source' => 'doi_fetch',
                            'content' => $fetchResult['text'],
                            'pdf_url' => null,
                            'pdf_available' => false,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Failed to fetch full text for paper {$paper->id}: " . $e->getMessage());
            }
        }

        // 3. 既に取得済みの本文があるかチェック
        if ($paper->hasFullText()) {
            return [
                'source' => 'full_text',
                'content' => $paper->full_text,
                'pdf_url' => null,
                'pdf_available' => false,
            ];
        }

        // 4. リモートPDF URL（ダウンロード済みでないPDF）がある場合
        if ($paper->pdf_url) {
            return [
                'source' => 'pdf',
                'content' => null,
                'pdf_url' => $paper->pdf_url,
                'pdf_available' => true,
            ];
        }

        // 5. アブストラクトがあればそれを使用
        if (!empty($paper->abstract)) {
            return [
                'source' => 'abstract',
                'content' => $paper->abstract,
                'pdf_url' => null,
                'pdf_available' => false,
            ];
        }

        // 6. 最終手段：タイトルと利用可能なメタデータのみ
        return [
            'source' => 'minimal',
            'content' => null,
            'pdf_url' => null,
            'pdf_available' => false,
        ];
    }

    /**
     * Generate summary for a paper
     *
     * @param Paper $paper
     * @param string $provider
     * @param string|null $model
     * @return array
     */
    public function generateSummary(Paper $paper, string $provider = 'openai', ?string $model = null): array
    {
        $startTime = microtime(true);

        // データソース優先順位に従ってコンテンツを準備
        $contentInfo = $this->prepareContentForSummary($paper);
        $inputSource = $contentInfo['source'];

        switch ($provider) {
            case 'claude':
                // ClaudeはPDFを直接入力できる
                if ($contentInfo['pdf_available']) {
                    $pdfUrl = $contentInfo['pdf_url'] ?? $paper->pdf_url;
                    if ($pdfUrl) {
                        $result = $this->callClaudeWithPdf($paper, $model);
                        $result['input_source'] = $inputSource;
                    } else {
                        // ローカルPDFのみの場合はテキストにフォールバック
                        $prompt = $this->buildPrompt($paper, $contentInfo);
                        $result = $this->callClaude($prompt, $model);
                        $result['input_source'] = $inputSource;
                    }
                } else {
                    $prompt = $this->buildPrompt($paper, $contentInfo);
                    $result = $this->callClaude($prompt, $model);
                    $result['input_source'] = $inputSource;
                }
                break;
            default:
                // OpenAIはテキストのみ対応
                $prompt = $this->buildPrompt($paper, $contentInfo);
                $result = $this->callOpenAI($prompt, $model);
                $result['input_source'] = $inputSource;
                break;
        }

        $endTime = microtime(true);
        $result['generation_time_ms'] = (int)(($endTime - $startTime) * 1000);

        return $result;
    }

    /**
     * Build prompt for text-based summary generation
     *
     * @param Paper $paper
     * @param array|null $contentInfo Content information from prepareContentForSummary()
     * @return string
     */
    private function buildPrompt(Paper $paper, ?array $contentInfo = null): string
    {
        $authors = is_array($paper->authors) ? implode(', ', $paper->authors) : ($paper->authors ?? '不明');
        $journalName = ($paper->journal && $paper->journal->name) ? $paper->journal->name : '不明';

        // contentInfoがなければ従来の方式（後方互換性）
        if ($contentInfo === null) {
            $contentInfo = [
                'source' => $paper->hasFullText() ? 'full_text' : 'abstract',
                'content' => $paper->getTextForSummary(),
                'pdf_available' => false,
            ];
        }

        $source = $contentInfo['source'];
        $content = $contentInfo['content'];

        // データソースに応じたラベルと説明
        $contentLabel = match ($source) {
            'full_text', 'doi_fetch' => '本文',
            'abstract' => 'アブストラクト',
            'minimal' => 'メタデータ',
            default => 'テキスト',
        };

        // 本文が長すぎる場合は切り詰め（トークン制限対策）
        $maxContentLength = 30000; // 約30,000文字（GPT-4oで約10,000トークン程度）
        if ($content && mb_strlen($content) > $maxContentLength) {
            $content = mb_substr($content, 0, $maxContentLength) . "\n\n[... 以下省略 ...]";
        }

        // 最小限のデータのみの場合は特別なプロンプト
        if ($source === 'minimal') {
            $prompt = <<<PROMPT
以下の学術論文について，タイトルと利用可能な情報のみに基づいて，日本語で要約を作成してください．

【論文情報】
タイトル: {$paper->title}
著者: {$authors}
論文誌: {$journalName}
公開日: {$paper->published_date}
DOI: {$paper->doi}

※ 注意：この論文の本文やアブストラクトにアクセスできませんでした．タイトルと上記のメタデータのみに基づいて，論文の内容を推測し，簡潔な要約を作成してください．推測に基づく部分はその旨を明記してください．

PROMPT;
        } else {
            $prompt = <<<PROMPT
以下の学術論文について，日本語で構造化された要約を作成してください．

【論文情報】
タイトル: {$paper->title}
著者: {$authors}
論文誌: {$journalName}

【{$contentLabel}】
{$content}

PROMPT;

            // 本文の場合は追加説明
            if (in_array($source, ['full_text', 'doi_fetch'])) {
                $prompt .= <<<PROMPT

※ 本文全体が提供されています．論文の主要な内容を網羅的に要約してください．

PROMPT;
            }
        }

        // ユーザーの調査観点設定を取得してプロンプトに組み込む
        $perspectivePrompt = $this->buildPerspectivePrompt();
        if ($perspectivePrompt) {
            $prompt .= $perspectivePrompt;
        }

        // ユーザーの要約テンプレートを取得
        $summaryTemplate = $this->user ? $this->user->summary_template : null;

        if (!empty($summaryTemplate)) {
            // ユーザー定義のテンプレートがある場合は，テンプレートに完全に従う
            $prompt .= <<<PROMPT

【要約形式の指定】
以下の指定に従って要約を作成してください：

{$summaryTemplate}

以下の形式のJSONで回答してください：
{
  "summary_text": "上記の指定形式に従った要約全文"
}

重要:
- JSON形式のみで回答し，他のテキストは含めないでください．
- summary_text には指定された形式に完全に従った要約を含めてください．
- 日本語の句読点は必ず「，」（カンマ）と「．」（ピリオド）を使用してください．「、」と「。」は絶対に使用しないでください．
PROMPT;
        } else {
            // デフォルトの形式（具体的で詳細な要約）
            $prompt .= <<<PROMPT

【要約作成の指針】
以下の点を必ず守って要約を作成してください：
- **抽象的な表現を避け，具体的な内容を明示する**：「〜を調査した」ではなく「〜という手法で〜を対象に〜を調査した」のように具体化
- **数値・固有名詞・専門用語を積極的に含める**：サンプルサイズ，効果量，p値，使用したモデル名，データセット名など
- **研究の核心となる主張・発見を明確に記述する**：この研究でしか得られない知見は何か
- **マークダウン形式で整理する**：箇条書き（- ）や強調（**太字**）を使用して可読性を高める

以下の形式のJSONで回答してください：
{
  "summary_text": "## 概要\n論文全体の要約をマークダウン形式で記述．研究の背景・目的・手法・主要な結果・意義を含む包括的な要約（5〜8文程度）．具体的な数値や手法名を含めること．",
  "purpose": "## 研究目的\n- **研究課題**: 具体的にどのような問いに答えようとしているか\n- **背景**: なぜこの研究が必要か，先行研究の限界は何か\n- **仮説**（あれば）: 検証しようとした仮説",
  "methodology": "## 研究手法\n- **研究デザイン**: 実験/調査/観察などの種類，縦断/横断など\n- **対象**: サンプルサイズ（N=XX），対象者の属性，選定基準\n- **データ収集**: 使用した尺度・測定方法・機器\n- **分析手法**: 統計手法，使用したモデル・アルゴリズム",
  "findings": "## 主な発見・結果\n- **主要な結果1**: 具体的な数値（効果量，p値，精度など）を含めて記述\n- **主要な結果2**: 同様に具体的に\n- **副次的な発見**（あれば）: 予想外の結果や付随的な知見",
  "implications": "## 示唆・意義\n- **理論的貢献**: この研究が学術的にどう貢献するか\n- **実践的示唆**: 現場や実務にどう活かせるか\n- **限界と今後の課題**: 研究の制約と将来の研究への示唆"
}

重要:
- JSON形式のみで回答し，他のテキストは含めないでください．
- 各フィールドの値はマークダウン形式の文字列として記述してください．
- 日本語の句読点は必ず「，」（カンマ）と「．」（ピリオド）を使用してください．「、」と「。」は絶対に使用しないでください．
- 情報が論文に記載されていない場合は「記載なし」と明記してください．
- 文章量が多くなっても構いません．研究の重要なポイントが要約だけから分かるようにしてください．
PROMPT;
        }

        return $prompt;
    }

    /**
     * Build perspective prompt from user's research perspective settings
     *
     * @return string|null
     */
    private function buildPerspectivePrompt(): ?string
    {
        if (!$this->user || !$this->user->research_perspective) {
            return null;
        }

        $perspective = $this->user->research_perspective;
        $hasContent = false;
        $promptParts = [];

        // 研究分野・興味のある観点
        if (!empty($perspective['research_fields'])) {
            $promptParts[] = "・読者の研究分野・興味: {$perspective['research_fields']}";
            $hasContent = true;
        }

        // 要約してほしい観点
        if (!empty($perspective['summary_perspective'])) {
            $promptParts[] = "・要約で重視してほしい観点: {$perspective['summary_perspective']}";
            $hasContent = true;
        }

        // 論文を読む際の着眼点
        if (!empty($perspective['reading_focus'])) {
            $promptParts[] = "・読者が論文を読む際の着眼点: {$perspective['reading_focus']}";
            $hasContent = true;
        }

        if (!$hasContent) {
            return null;
        }

        $perspectiveText = implode("\n", $promptParts);

        return <<<PROMPT

【読者の調査観点】
以下は要約を依頼している読者の研究背景や関心です．この観点を考慮して要約を作成してください．

{$perspectiveText}

上記の観点を踏まえ，読者にとって特に関連性の高い内容や示唆を含めるようにしてください．

PROMPT;
    }

    private function callClaude(string $prompt, ?string $model = null): array
    {
        // Use user's effective API key (includes admin env fallback for admin users)
        $apiKey = $this->user?->getEffectiveClaudeApiKey();

        if (!$apiKey) {
            throw new \Exception('Claude API key is not configured. Please set your API key in Settings.');
        }

        $model = $model ?? config('services.ai.claude_default_model', 'claude-sonnet-4-20250514');

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
            Log::error('Claude API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Claude API request failed: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['content'][0]['text'] ?? '';
        $tokensUsed = ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0);

        $parsed = $this->parseJsonResponse($content);

        return [
            'provider' => 'claude',
            'model' => $model,
            'summary_text' => $parsed['summary_text'] ?? $content,
            'purpose' => $parsed['purpose'] ?? null,
            'methodology' => $parsed['methodology'] ?? null,
            'findings' => $parsed['findings'] ?? null,
            'implications' => $parsed['implications'] ?? null,
            'tokens_used' => $tokensUsed,
        ];
    }

    /**
     * Call Claude API with PDF document
     *
     * @param Paper $paper
     * @param string|null $model
     * @return array
     */
    private function callClaudeWithPdf(Paper $paper, ?string $model = null): array
    {
        $apiKey = $this->user?->getEffectiveClaudeApiKey();

        if (!$apiKey) {
            throw new \Exception('Claude API key is not configured. Please set your API key in Settings.');
        }

        $model = $model ?? config('services.ai.claude_default_model', 'claude-sonnet-4-20250514');

        // PDFをダウンロードしてbase64エンコード
        $pdfData = $this->downloadPdfAsBase64($paper->pdf_url);

        if (!$pdfData) {
            // PDFダウンロードに失敗した場合はテキストにフォールバック
            Log::warning("PDF download failed for paper {$paper->id}, falling back to text");
            return $this->callClaude($this->buildPrompt($paper), $model);
        }

        // プロンプトを構築（PDF用）
        $prompt = $this->buildPromptForPdf($paper);

        // Claude APIリクエスト（PDF添付）
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(180)->post(self::CLAUDE_API_URL, [
            'model' => $model,
            'max_tokens' => 4000,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'document',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'application/pdf',
                                'data' => $pdfData,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ]);

        if (!$response->successful()) {
            Log::error('Claude API error (PDF)', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            // PDFでのリクエストが失敗した場合はテキストにフォールバック
            Log::warning("Claude PDF request failed for paper {$paper->id}, falling back to text");
            return $this->callClaude($this->buildPrompt($paper), $model);
        }

        $data = $response->json();
        $content = $data['content'][0]['text'] ?? '';
        $tokensUsed = ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0);

        $parsed = $this->parseJsonResponse($content);

        return [
            'provider' => 'claude',
            'model' => $model,
            'summary_text' => $parsed['summary_text'] ?? $content,
            'purpose' => $parsed['purpose'] ?? null,
            'methodology' => $parsed['methodology'] ?? null,
            'findings' => $parsed['findings'] ?? null,
            'implications' => $parsed['implications'] ?? null,
            'tokens_used' => $tokensUsed,
            'input_source' => 'pdf',
        ];
    }

    /**
     * Download PDF from URL and convert to base64
     *
     * @param string $url
     * @return string|null
     */
    private function downloadPdfAsBase64(string $url): ?string
    {
        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SurveyMate/1.0; Academic Research)',
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::warning("Failed to download PDF: HTTP {$response->status()}");
                return null;
            }

            $contentType = $response->header('Content-Type');
            if ($contentType && !str_contains($contentType, 'pdf') && !str_contains($contentType, 'octet-stream')) {
                Log::warning("Downloaded content is not PDF: {$contentType}");
                return null;
            }

            $pdfContent = $response->body();

            // PDFサイズチェック（Claude制限: 32MB）
            if (strlen($pdfContent) > 30 * 1024 * 1024) {
                Log::warning("PDF too large for Claude API");
                return null;
            }

            return base64_encode($pdfContent);

        } catch (\Exception $e) {
            Log::error("PDF download exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Build prompt for PDF input
     *
     * @param Paper $paper
     * @return string
     */
    private function buildPromptForPdf(Paper $paper): string
    {
        $authors = is_array($paper->authors) ? implode(', ', $paper->authors) : ($paper->authors ?? '不明');
        $journalName = ($paper->journal && $paper->journal->name) ? $paper->journal->name : '不明';

        $prompt = <<<PROMPT
添付されたPDFは以下の学術論文です．この論文について，日本語で構造化された要約を作成してください．

【論文情報】
タイトル: {$paper->title}
著者: {$authors}
論文誌: {$journalName}

※ 論文本文のPDFが添付されています．論文の主要な内容を網羅的に要約してください．

PROMPT;

        // ユーザーの調査観点設定を取得してプロンプトに組み込む
        $perspectivePrompt = $this->buildPerspectivePrompt();
        if ($perspectivePrompt) {
            $prompt .= $perspectivePrompt;
        }

        // ユーザーの要約テンプレートを取得
        $summaryTemplate = $this->user ? $this->user->summary_template : null;

        if (!empty($summaryTemplate)) {
            $prompt .= <<<PROMPT

【要約形式の指定】
以下の指定に従って要約を作成してください：

{$summaryTemplate}

以下の形式のJSONで回答してください：
{
  "summary_text": "上記の指定形式に従った要約全文"
}

重要:
- JSON形式のみで回答し，他のテキストは含めないでください．
- summary_text には指定された形式に完全に従った要約を含めてください．
- 日本語の句読点は必ず「，」（カンマ）と「．」（ピリオド）を使用してください．「、」と「。」は絶対に使用しないでください．
PROMPT;
        } else {
            // デフォルトの形式（具体的で詳細な要約）
            $prompt .= <<<PROMPT

【要約作成の指針】
以下の点を必ず守って要約を作成してください：
- **抽象的な表現を避け，具体的な内容を明示する**：「〜を調査した」ではなく「〜という手法で〜を対象に〜を調査した」のように具体化
- **数値・固有名詞・専門用語を積極的に含める**：サンプルサイズ，効果量，p値，使用したモデル名，データセット名など
- **研究の核心となる主張・発見を明確に記述する**：この研究でしか得られない知見は何か
- **マークダウン形式で整理する**：箇条書き（- ）や強調（**太字**）を使用して可読性を高める

以下の形式のJSONで回答してください：
{
  "summary_text": "## 概要\n論文全体の要約をマークダウン形式で記述．研究の背景・目的・手法・主要な結果・意義を含む包括的な要約（5〜8文程度）．具体的な数値や手法名を含めること．",
  "purpose": "## 研究目的\n- **研究課題**: 具体的にどのような問いに答えようとしているか\n- **背景**: なぜこの研究が必要か，先行研究の限界は何か\n- **仮説**（あれば）: 検証しようとした仮説",
  "methodology": "## 研究手法\n- **研究デザイン**: 実験/調査/観察などの種類，縦断/横断など\n- **対象**: サンプルサイズ（N=XX），対象者の属性，選定基準\n- **データ収集**: 使用した尺度・測定方法・機器\n- **分析手法**: 統計手法，使用したモデル・アルゴリズム",
  "findings": "## 主な発見・結果\n- **主要な結果1**: 具体的な数値（効果量，p値，精度など）を含めて記述\n- **主要な結果2**: 同様に具体的に\n- **副次的な発見**（あれば）: 予想外の結果や付随的な知見",
  "implications": "## 示唆・意義\n- **理論的貢献**: この研究が学術的にどう貢献するか\n- **実践的示唆**: 現場や実務にどう活かせるか\n- **限界と今後の課題**: 研究の制約と将来の研究への示唆"
}

重要:
- JSON形式のみで回答し，他のテキストは含めないでください．
- 各フィールドの値はマークダウン形式の文字列として記述してください．
- 日本語の句読点は必ず「，」（カンマ）と「．」（ピリオド）を使用してください．「、」と「。」は絶対に使用しないでください．
- 情報が論文に記載されていない場合は「記載なし」と明記してください．
- 文章量が多くなっても構いません．研究の重要なポイントが要約だけから分かるようにしてください．
PROMPT;
        }

        return $prompt;
    }

    private function callOpenAI(string $prompt, ?string $model = null): array
    {
        // Use user's effective API key (includes admin env fallback for admin users)
        $apiKey = $this->user?->getEffectiveOpenaiApiKey();

        if (!$apiKey) {
            throw new \Exception('OpenAI API key is not configured. Please set your API key in Settings.');
        }

        $model = $model ?? config('services.ai.openai_default_model', 'gpt-4o');

        // Build request body
        $requestBody = [
            'model' => $model,
            'temperature' => 0.3,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an academic paper summarization assistant. Always respond in valid JSON format. When writing in Japanese, always use「，」(comma) and「．」(period) for punctuation. Never use「、」or「。」.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        // Use max_completion_tokens for newer models, max_tokens for older ones
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
            Log::error('OpenAI API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('OpenAI API request failed: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? '';
        $tokensUsed = $data['usage']['total_tokens'] ?? 0;

        $parsed = $this->parseJsonResponse($content);

        return [
            'provider' => 'openai',
            'model' => $model,
            'summary_text' => $parsed['summary_text'] ?? $content,
            'purpose' => $parsed['purpose'] ?? null,
            'methodology' => $parsed['methodology'] ?? null,
            'findings' => $parsed['findings'] ?? null,
            'implications' => $parsed['implications'] ?? null,
            'tokens_used' => $tokensUsed,
        ];
    }

    /**
     * Generate custom summary from a prompt (for trend analysis etc.)
     *
     * @param string $prompt
     * @param string|null $provider
     * @param string|null $model
     * @return array
     */
    public function generateCustomSummary(string $prompt, ?string $provider = null, ?string $model = null): array
    {
        $provider = $provider ?? config('services.ai.provider', 'openai');

        switch ($provider) {
            case 'claude':
                $result = $this->callClaudeCustom($prompt, $model);
                break;
            default:
                $result = $this->callOpenAICustom($prompt, $model);
                break;
        }

        return $result;
    }

    private function callClaudeCustom(string $prompt, ?string $model = null): array
    {
        // Use user's effective API key (includes admin env fallback for admin users)
        $apiKey = $this->user?->getEffectiveClaudeApiKey();

        if (!$apiKey) {
            throw new \Exception('Claude API key is not configured.');
        }

        $model = $model ?? config('services.ai.claude_default_model', 'claude-sonnet-4-20250514');

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(180)->post(self::CLAUDE_API_URL, [
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

    private function callOpenAICustom(string $prompt, ?string $model = null): array
    {
        // Use user's effective API key (includes admin env fallback for admin users)
        $apiKey = $this->user?->getEffectiveOpenaiApiKey();

        if (!$apiKey) {
            throw new \Exception('OpenAI API key is not configured.');
        }

        $model = $model ?? config('services.ai.openai_default_model', 'gpt-4o');

        // Build request body
        $requestBody = [
            'model' => $model,
            'temperature' => 0.3,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an academic research trend analysis assistant. Always respond in valid JSON format. When writing in Japanese, always use「，」(comma) and「．」(period) for punctuation. Never use「、」or「。」.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        // Use max_completion_tokens for newer models, max_tokens for older ones
        if ($this->isNewOpenAIModel($model)) {
            $requestBody['max_completion_tokens'] = 4000;
        } else {
            $requestBody['max_tokens'] = 4000;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(180)->post(self::OPENAI_API_URL, $requestBody);

        if (!$response->successful()) {
            Log::error('OpenAI API error (custom)', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('OpenAI API request failed: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? '';

        return $this->parseJsonResponse($content);
    }

    /**
     * Parse comma-separated model list from config
     *
     * @param string $modelList
     * @return array
     */
    private function parseModelList(string $modelList): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $modelList))));
    }

    /**
     * Check if model uses new OpenAI API format (max_completion_tokens)
     * Newer models like gpt-4o, gpt-4-turbo, o1, o3 use max_completion_tokens
     * Older models like gpt-3.5-turbo use max_tokens
     *
     * @param string $model
     * @return bool
     */
    private function isNewOpenAIModel(string $model): bool
    {
        // gpt-4o variants, gpt-4-turbo, and o-series models use max_completion_tokens
        return strpos($model, 'gpt-4o') !== false
            || strpos($model, 'gpt-4-turbo') !== false
            || preg_match('/^o[0-9]/', $model) === 1;  // o1, o1-mini, o3, o3-mini, etc.
    }

    private function parseJsonResponse(string $content): array
    {
        // Remove markdown code block if present
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
            return ['summary_text' => $content];
        }
    }

    /**
     * Chat about a summary (follow-up questions)
     *
     * @param Summary $summary
     * @param string $userMessage
     * @param array $history Previous messages for context
     * @param string|null $provider
     * @param string|null $model
     * @return array
     */
    public function chat(Summary $summary, string $userMessage, array $history = [], ?string $provider = null, ?string $model = null): array
    {
        $paper = $summary->paper;
        $provider = $provider ?? $summary->ai_provider ?? 'openai';
        $model = $model ?? $summary->ai_model ?? null;

        switch ($provider) {
            case 'claude':
                // ClaudeはPDFを直接入力できるので，PDF URLがあればPDFを送信
                if ($paper->pdf_url) {
                    $result = $this->callClaudeChatWithPdf($paper, $summary, $userMessage, $history, $model);
                    if ($result !== null) {
                        return $result;
                    }
                    // PDFでの処理に失敗した場合はテキストにフォールバック
                }
                $systemContext = $this->buildChatSystemContext($paper, $summary);
                $messages = $this->buildChatMessages($systemContext, $history, $userMessage);
                $result = $this->callClaudeChat($messages, $model);
                break;
            default:
                // OpenAIはテキストのみ対応
                $systemContext = $this->buildChatSystemContext($paper, $summary);
                $messages = $this->buildChatMessages($systemContext, $history, $userMessage);
                $result = $this->callOpenAIChat($messages, $model);
                break;
        }

        return $result;
    }

    private function buildChatSystemContext(Paper $paper, Summary $summary): string
    {
        $authors = is_array($paper->authors) ? implode(', ', $paper->authors) : ($paper->authors ?? '不明');
        $journalName = ($paper->journal && $paper->journal->name) ? $paper->journal->name : '不明';

        $summaryText = $summary->summary_text ?? '';
        $purpose = $summary->purpose ? "【研究目的】{$summary->purpose}" : '';
        $methodology = $summary->methodology ? "【手法】{$summary->methodology}" : '';
        $findings = $summary->findings ? "【主な発見】{$summary->findings}" : '';
        $implications = $summary->implications ? "【教育への示唆】{$summary->implications}" : '';

        // 本文があれば使用（チャット用に20,000文字に制限）
        $hasFullText = $paper->hasFullText();
        $contentLabel = $hasFullText ? '本文（抜粋）' : 'アブストラクト';
        $content = $hasFullText
            ? mb_substr($paper->full_text, 0, 20000)
            : $paper->abstract;

        return <<<CONTEXT
あなたは学術論文についての質問に答えるアシスタントです．以下の論文とその要約に基づいて，ユーザーの質問に日本語で回答してください．

【論文情報】
タイトル: {$paper->title}
著者: {$authors}
論文誌: {$journalName}
公開日: {$paper->published_date}

【{$contentLabel}】
{$content}

【AI要約】
{$summaryText}
{$purpose}
{$methodology}
{$findings}
{$implications}

上記の情報に基づいて，ユーザーの質問に簡潔かつ正確に回答してください．論文の内容を超える推測は避け，わからない場合はその旨を伝えてください．

重要: 日本語の句読点は必ず「，」（カンマ）と「．」（ピリオド）を使用してください．「、」と「。」は絶対に使用しないでください．
CONTEXT;
    }

    private function buildChatMessages(string $systemContext, array $history, string $userMessage): array
    {
        $messages = [];

        // Add history messages
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        // Add current user message
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage,
        ];

        return [
            'system' => $systemContext,
            'messages' => $messages,
        ];
    }

    private function callClaudeChat(array $chatData, ?string $model = null): array
    {
        // Use user's effective API key (includes admin env fallback for admin users)
        $apiKey = $this->user?->getEffectiveClaudeApiKey();

        if (!$apiKey) {
            throw new \Exception('Claude API key is not configured.');
        }

        $model = $model ?? config('services.ai.claude_default_model', 'claude-sonnet-4-20250514');

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(60)->post(self::CLAUDE_API_URL, [
            'model' => $model,
            'max_tokens' => 1500,
            'system' => $chatData['system'],
            'messages' => $chatData['messages'],
        ]);

        if (!$response->successful()) {
            Log::error('Claude Chat API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Claude API request failed: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['content'][0]['text'] ?? '';
        $tokensUsed = ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0);

        return [
            'content' => $content,
            'provider' => 'claude',
            'model' => $model,
            'tokens_used' => $tokensUsed,
        ];
    }

    /**
     * Call Claude Chat API with PDF document
     *
     * @param Paper $paper
     * @param Summary $summary
     * @param string $userMessage
     * @param array $history
     * @param string|null $model
     * @return array|null Returns null if PDF processing fails
     */
    private function callClaudeChatWithPdf(Paper $paper, Summary $summary, string $userMessage, array $history, ?string $model = null): ?array
    {
        $apiKey = $this->user?->getEffectiveClaudeApiKey();

        if (!$apiKey) {
            throw new \Exception('Claude API key is not configured.');
        }

        $model = $model ?? config('services.ai.claude_default_model', 'claude-sonnet-4-20250514');

        // PDFをダウンロードしてbase64エンコード
        $pdfData = $this->downloadPdfAsBase64($paper->pdf_url);

        if (!$pdfData) {
            Log::warning("PDF download failed for chat, paper {$paper->id}");
            return null; // フォールバックを呼び出し側に任せる
        }

        // システムコンテキストを構築（PDF用）
        $systemContext = $this->buildChatSystemContextForPdf($paper, $summary);

        // メッセージを構築
        $messages = [];

        // 最初のメッセージにPDFを含める
        $firstUserContent = [
            [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => $pdfData,
                ],
            ],
            [
                'type' => 'text',
                'text' => "上記のPDFは論文の本文です．以下の会話でこの論文について質問に答えてください．",
            ],
        ];
        $messages[] = ['role' => 'user', 'content' => $firstUserContent];
        $messages[] = ['role' => 'assistant', 'content' => '論文のPDFを確認しました．ご質問にお答えします．'];

        // 履歴を追加
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        // 現在のユーザーメッセージを追加
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(self::CLAUDE_API_URL, [
            'model' => $model,
            'max_tokens' => 1500,
            'system' => $systemContext,
            'messages' => $messages,
        ]);

        if (!$response->successful()) {
            Log::error('Claude Chat API error (PDF)', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null; // フォールバックを呼び出し側に任せる
        }

        $data = $response->json();
        $content = $data['content'][0]['text'] ?? '';
        $tokensUsed = ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0);

        return [
            'content' => $content,
            'provider' => 'claude',
            'model' => $model,
            'tokens_used' => $tokensUsed,
            'input_source' => 'pdf',
        ];
    }

    /**
     * Build chat system context for PDF input
     */
    private function buildChatSystemContextForPdf(Paper $paper, Summary $summary): string
    {
        $authors = is_array($paper->authors) ? implode(', ', $paper->authors) : ($paper->authors ?? '不明');
        $journalName = ($paper->journal && $paper->journal->name) ? $paper->journal->name : '不明';

        $summaryText = $summary->summary_text ?? '';
        $purpose = $summary->purpose ? "【研究目的】{$summary->purpose}" : '';
        $methodology = $summary->methodology ? "【手法】{$summary->methodology}" : '';
        $findings = $summary->findings ? "【主な発見】{$summary->findings}" : '';
        $implications = $summary->implications ? "【教育への示唆】{$summary->implications}" : '';

        return <<<CONTEXT
あなたは学術論文についての質問に答えるアシスタントです．添付されたPDF論文とその要約に基づいて，ユーザーの質問に日本語で回答してください．

【論文情報】
タイトル: {$paper->title}
著者: {$authors}
論文誌: {$journalName}
公開日: {$paper->published_date}

【AI要約】
{$summaryText}
{$purpose}
{$methodology}
{$findings}
{$implications}

※ 論文本文のPDFが添付されています．PDFの内容を参照して，ユーザーの質問に簡潔かつ正確に回答してください．論文の内容を超える推測は避け，わからない場合はその旨を伝えてください．

重要: 日本語の句読点は必ず「，」（カンマ）と「．」（ピリオド）を使用してください．「、」と「。」は絶対に使用しないでください．
CONTEXT;
    }

    private function callOpenAIChat(array $chatData, ?string $model = null): array
    {
        // Use user's effective API key (includes admin env fallback for admin users)
        $apiKey = $this->user?->getEffectiveOpenaiApiKey();

        if (!$apiKey) {
            throw new \Exception('OpenAI API key is not configured.');
        }

        $model = $model ?? config('services.ai.openai_default_model', 'gpt-4o');

        // Build messages with system context
        $messages = [
            ['role' => 'system', 'content' => $chatData['system']],
            ...$chatData['messages'],
        ];

        $requestBody = [
            'model' => $model,
            'temperature' => 0.5,
            'messages' => $messages,
        ];

        if ($this->isNewOpenAIModel($model)) {
            $requestBody['max_completion_tokens'] = 1500;
        } else {
            $requestBody['max_tokens'] = 1500;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(60)->post(self::OPENAI_API_URL, $requestBody);

        if (!$response->successful()) {
            Log::error('OpenAI Chat API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('OpenAI API request failed: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? '';
        $tokensUsed = $data['usage']['total_tokens'] ?? 0;

        return [
            'content' => $content,
            'provider' => 'openai',
            'model' => $model,
            'tokens_used' => $tokensUsed,
        ];
    }

    /**
     * Generate a summary for papers with a specific tag
     *
     * @param Tag $tag
     * @param array $papers Papers collection
     * @param string $perspectivePrompt Custom perspective/viewpoint prompt
     * @param string|null $provider
     * @param string|null $model
     * @return array
     */
    public function generateTagSummary(Tag $tag, array $papers, string $perspectivePrompt, ?string $provider = null, ?string $model = null): array
    {
        $startTime = microtime(true);

        $prompt = $this->buildTagSummaryPrompt($tag, $papers, $perspectivePrompt);

        $provider = $provider ?? config('services.ai.provider', 'openai');

        switch ($provider) {
            case 'claude':
                $result = $this->callClaudeTagSummary($prompt, $model);
                break;
            default:
                $result = $this->callOpenAITagSummary($prompt, $model);
                break;
        }

        $endTime = microtime(true);
        $result['generation_time_ms'] = (int)(($endTime - $startTime) * 1000);
        $result['paper_count'] = count($papers);

        return $result;
    }

    private function buildTagSummaryPrompt(Tag $tag, array $papers, string $perspectivePrompt): string
    {
        $paperList = '';
        foreach ($papers as $index => $paper) {
            $num = $index + 1;
            $authors = is_array($paper['authors']) ? implode(', ', $paper['authors']) : ($paper['authors'] ?? '不明');
            $abstract = $paper['abstract'] ?? '（概要なし）';
            // アブストラクトを500文字に制限
            if (mb_strlen($abstract) > 500) {
                $abstract = mb_substr($abstract, 0, 500) . '...';
            }

            $paperList .= <<<PAPER

【論文{$num}】
タイトル: {$paper['title']}
著者: {$authors}
論文誌: {$paper['journal_name']}
公開日: {$paper['published_date']}
概要: {$abstract}

PAPER;
        }

        // タグの説明（グループの要約観点）を取得
        $tagDescription = '';
        if ($tag->description) {
            $tagDescription = <<<TAG_DESC

【タググループの説明】
{$tag->description}

TAG_DESC;
        }

        // ユーザーの調査観点設定を取得
        $userPerspective = $this->buildPerspectivePrompt();
        if ($userPerspective) {
            $userPerspective = "\n" . trim($userPerspective) . "\n";
        } else {
            $userPerspective = '';
        }

        $prompt = <<<PROMPT
以下は「{$tag->name}」というタグでグループ化された{$this->countPapers($papers)}本の学術論文です．
{$tagDescription}
{$paperList}
{$userPerspective}
【要約の観点】
{$perspectivePrompt}

上記の情報に基づいて，これらの論文群を俯瞰的に分析し，日本語で要約してください．
読者の研究背景や関心がある場合は，それを踏まえて特に関連性の高い内容を強調してください．

以下の形式のJSONで回答してください：
{
  "overview": "論文群全体の概要と傾向（3〜5文）",
  "key_themes": ["主要テーマ1", "主要テーマ2", "主要テーマ3"],
  "common_findings": "共通する発見や結論（2〜3文）",
  "research_gaps": "研究の隙間や今後の課題（1〜2文）",
  "perspective_analysis": "指定された観点からの分析（3〜5文）"
}

重要:
- JSON形式のみで回答し，他のテキストは含めないでください．
- 日本語の句読点は必ず「，」（カンマ）と「．」（ピリオド）を使用してください．「、」と「。」は絶対に使用しないでください．
PROMPT;

        return $prompt;
    }

    private function countPapers(array $papers): int
    {
        return count($papers);
    }

    private function callClaudeTagSummary(string $prompt, ?string $model = null): array
    {
        // Use user's effective API key (includes admin env fallback for admin users)
        $apiKey = $this->user?->getEffectiveClaudeApiKey();

        if (!$apiKey) {
            throw new \Exception('Claude API key is not configured.');
        }

        $model = $model ?? config('services.ai.claude_default_model', 'claude-sonnet-4-20250514');

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(180)->post(self::CLAUDE_API_URL, [
            'model' => $model,
            'max_tokens' => 3000,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if (!$response->successful()) {
            Log::error('Claude Tag Summary API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Claude API request failed: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['content'][0]['text'] ?? '';
        $tokensUsed = ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0);

        $parsed = $this->parseJsonResponse($content);

        return [
            'provider' => 'claude',
            'model' => $model,
            'summary_text' => $this->formatTagSummaryText($parsed),
            'raw_response' => $parsed,
            'tokens_used' => $tokensUsed,
        ];
    }

    private function callOpenAITagSummary(string $prompt, ?string $model = null): array
    {
        // Use user's effective API key (includes admin env fallback for admin users)
        $apiKey = $this->user?->getEffectiveOpenaiApiKey();

        if (!$apiKey) {
            throw new \Exception('OpenAI API key is not configured.');
        }

        $model = $model ?? config('services.ai.openai_default_model', 'gpt-4o');

        $requestBody = [
            'model' => $model,
            'temperature' => 0.3,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an academic research analysis assistant. Always respond in valid JSON format. When writing in Japanese, always use「，」(comma) and「．」(period) for punctuation. Never use「、」or「。」.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if ($this->isNewOpenAIModel($model)) {
            $requestBody['max_completion_tokens'] = 3000;
        } else {
            $requestBody['max_tokens'] = 3000;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(180)->post(self::OPENAI_API_URL, $requestBody);

        if (!$response->successful()) {
            Log::error('OpenAI Tag Summary API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('OpenAI API request failed: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? '';
        $tokensUsed = $data['usage']['total_tokens'] ?? 0;

        $parsed = $this->parseJsonResponse($content);

        return [
            'provider' => 'openai',
            'model' => $model,
            'summary_text' => $this->formatTagSummaryText($parsed),
            'raw_response' => $parsed,
            'tokens_used' => $tokensUsed,
        ];
    }

    private function formatTagSummaryText(array $parsed): string
    {
        $text = '';

        if (!empty($parsed['overview'])) {
            $text .= "【概要】\n{$parsed['overview']}\n\n";
        }

        if (!empty($parsed['key_themes']) && is_array($parsed['key_themes'])) {
            $themes = implode('、', $parsed['key_themes']);
            $text .= "【主要テーマ】\n{$themes}\n\n";
        }

        if (!empty($parsed['common_findings'])) {
            $text .= "【共通する発見】\n{$parsed['common_findings']}\n\n";
        }

        if (!empty($parsed['research_gaps'])) {
            $text .= "【研究の課題】\n{$parsed['research_gaps']}\n\n";
        }

        if (!empty($parsed['perspective_analysis'])) {
            $text .= "【観点からの分析】\n{$parsed['perspective_analysis']}";
        }

        return trim($text) ?: ($parsed['summary_text'] ?? json_encode($parsed, JSON_UNESCAPED_UNICODE));
    }
}
