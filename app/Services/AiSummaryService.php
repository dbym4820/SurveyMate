<?php

namespace App\Services;

use App\Models\Paper;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiSummaryService
{
    private const CLAUDE_API_URL = 'https://api.anthropic.com/v1/messages';
    private const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';

    /** @var User|null */
    private $user;

    /**
     * Set the user context for API key retrieval
     *
     * @param User $user
     * @return self
     */
    public function setUser(User $user): self
    {
        $this->user = $user;
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

        // Check user's API keys first, then fall back to system config
        $hasClaudeKey = ($this->user && $this->user->hasClaudeApiKey()) || config('services.ai.claude_api_key');
        $hasOpenaiKey = ($this->user && $this->user->hasOpenaiApiKey()) || config('services.ai.openai_api_key');

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
            ];
        }

        return $providers;
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

        $prompt = $this->buildPrompt($paper);

        switch ($provider) {
            case 'claude':
                $result = $this->callClaude($prompt, $model);
                break;
            default:
                $result = $this->callOpenAI($prompt, $model);
                break;
        }

        $endTime = microtime(true);
        $result['generation_time_ms'] = (int)(($endTime - $startTime) * 1000);

        return $result;
    }

    private function buildPrompt(Paper $paper): string
    {
        $authors = is_array($paper->authors) ? implode(', ', $paper->authors) : ($paper->authors ?? '不明');
        $journalName = ($paper->journal && $paper->journal->name) ? $paper->journal->name : '不明';

        return <<<PROMPT
以下の学術論文について，日本語で構造化された要約を作成してください．

【論文情報】
タイトル: {$paper->title}
著者: {$authors}
論文誌: {$journalName}

【アブストラクト】
{$paper->abstract}

以下の形式のJSONで回答してください：
{
  "summary_text": "論文全体の要約（3〜4文）",
  "purpose": "研究目的（1〜2文）",
  "methodology": "研究手法（1〜2文）",
  "findings": "主な発見・結果（2〜3文）",
  "implications": "教育への示唆・実践的意義（1文）"
}

重要: JSON形式のみで回答し，他のテキストは含めないでください．
PROMPT;
    }

    private function callClaude(string $prompt, ?string $model = null): array
    {
        // Use user's API key if available, otherwise fall back to system config
        $apiKey = null;
        if ($this->user && $this->user->hasClaudeApiKey()) {
            $apiKey = $this->user->claude_api_key;
        } else {
            $apiKey = config('services.ai.claude_api_key');
        }

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
            'max_tokens' => 2000,
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

    private function callOpenAI(string $prompt, ?string $model = null): array
    {
        // Use user's API key if available, otherwise fall back to system config
        $apiKey = null;
        if ($this->user && $this->user->hasOpenaiApiKey()) {
            $apiKey = $this->user->openai_api_key;
        } else {
            $apiKey = config('services.ai.openai_api_key');
        }

        if (!$apiKey) {
            throw new \Exception('OpenAI API key is not configured. Please set your API key in Settings.');
        }

        $model = $model ?? config('services.ai.openai_default_model', 'gpt-4o');

        // Build request body
        $requestBody = [
            'model' => $model,
            'temperature' => 0.3,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an academic paper summarization assistant. Always respond in valid JSON format.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        // Use max_completion_tokens for newer models, max_tokens for older ones
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
        $apiKey = null;
        if ($this->user && $this->user->hasClaudeApiKey()) {
            $apiKey = $this->user->claude_api_key;
        } else {
            $apiKey = config('services.ai.claude_api_key');
        }

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
        $apiKey = null;
        if ($this->user && $this->user->hasOpenaiApiKey()) {
            $apiKey = $this->user->openai_api_key;
        } else {
            $apiKey = config('services.ai.openai_api_key');
        }

        if (!$apiKey) {
            throw new \Exception('OpenAI API key is not configured.');
        }

        $model = $model ?? config('services.ai.openai_default_model', 'gpt-4o');

        // Build request body
        $requestBody = [
            'model' => $model,
            'temperature' => 0.3,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an academic research trend analysis assistant. Always respond in valid JSON format.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        // Use max_completion_tokens for newer models, max_tokens for older ones
        if (strpos($model, 'gpt-4o') !== false || strpos($model, 'gpt-4-turbo') !== false) {
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
}
