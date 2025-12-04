<?php

namespace App\Services;

use App\Models\Paper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiSummaryService
{
    private const CLAUDE_API_URL = 'https://api.anthropic.com/v1/messages';
    private const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';

    public function getAvailableProviders(): array
    {
        $providers = [];

        if (config('services.ai.claude_api_key')) {
            $providers[] = [
                'id' => 'claude',
                'name' => 'Claude',
                'models' => [
                    config('services.ai.claude_model', 'claude-sonnet-4-5-20250929'),
                ],
                'default_model' => config('services.ai.claude_model', 'claude-sonnet-4-5-20250929'),
            ];
        }

        if (config('services.ai.openai_api_key')) {
            $providers[] = [
                'id' => 'openai',
                'name' => 'OpenAI',
                'models' => [
                    config('services.ai.openai_model', 'gpt-4o'),
                    'gpt-4-turbo',
                    'gpt-3.5-turbo',
                ],
                'default_model' => config('services.ai.openai_model', 'gpt-4o'),
            ];
        }

        return $providers;
    }

    public function generateSummary(Paper $paper, string $provider = 'claude', ?string $model = null): array
    {
        $startTime = microtime(true);

        $prompt = $this->buildPrompt($paper);

        $result = match ($provider) {
            'openai' => $this->callOpenAI($prompt, $model),
            default => $this->callClaude($prompt, $model),
        };

        $endTime = microtime(true);
        $result['generation_time_ms'] = (int)(($endTime - $startTime) * 1000);

        return $result;
    }

    private function buildPrompt(Paper $paper): string
    {
        $authors = is_array($paper->authors) ? implode(', ', $paper->authors) : ($paper->authors ?? '不明');
        $journalName = $paper->journal?->full_name ?? '不明';

        return <<<PROMPT
以下の学術論文について、日本語で構造化された要約を作成してください。

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

重要: JSON形式のみで回答し、他のテキストは含めないでください。
PROMPT;
    }

    private function callClaude(string $prompt, ?string $model = null): array
    {
        $apiKey = config('services.ai.claude_api_key');
        if (!$apiKey) {
            throw new \Exception('Claude API key is not configured');
        }

        $model = $model ?? config('services.ai.claude_model', 'claude-sonnet-4-5-20250929');

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
        $apiKey = config('services.ai.openai_api_key');
        if (!$apiKey) {
            throw new \Exception('OpenAI API key is not configured');
        }

        $model = $model ?? config('services.ai.openai_model', 'gpt-4o');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(self::OPENAI_API_URL, [
            'model' => $model,
            'max_tokens' => 2000,
            'temperature' => 0.3,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

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
