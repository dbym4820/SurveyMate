<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Services\AiSummaryService;
use App\Models\Journal;

class SettingsController extends Controller
{
    private AiSummaryService $aiService;

    public function __construct(AiSummaryService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Get current user's API settings (without revealing full keys)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getApiSettings(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        // Get full provider list with models
        $this->aiService->setUser($user);
        $providers = $this->aiService->getAvailableProviders();

        // 管理者の場合は .env のキーも考慮した実効キー情報を返す
        $claudeKeySet = $user->hasEffectiveClaudeApiKey();
        $openaiKeySet = $user->hasEffectiveOpenaiApiKey();
        $claudeKeyMasked = $this->maskApiKey($user->getEffectiveClaudeApiKey());
        $openaiKeyMasked = $this->maskApiKey($user->getEffectiveOpenaiApiKey());

        return response()->json([
            'success' => true,
            'claude_api_key_set' => $claudeKeySet,
            'claude_api_key_masked' => $claudeKeyMasked,
            'claude_api_key_from_env' => $user->isClaudeApiKeyFromEnv(),
            'openai_api_key_set' => $openaiKeySet,
            'openai_api_key_masked' => $openaiKeyMasked,
            'openai_api_key_from_env' => $user->isOpenaiApiKeyFromEnv(),
            'preferred_ai_provider' => $user->preferred_ai_provider,
            'preferred_openai_model' => $user->preferred_openai_model,
            'preferred_claude_model' => $user->preferred_claude_model,
            'available_providers' => $providers,
            'is_admin' => $user->is_admin,
        ]);
    }

    /**
     * Update user's API keys
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateApiSettings(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $validator = Validator::make($request->all(), [
            'claude_api_key' => 'nullable|string|max:500',
            'openai_api_key' => 'nullable|string|max:500',
            'preferred_ai_provider' => 'nullable|string|in:claude,openai',
            'preferred_openai_model' => 'nullable|string|max:100',
            'preferred_claude_model' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $updated = [];

        // Update Claude API key if provided
        if (array_key_exists('claude_api_key', $data)) {
            if ($data['claude_api_key'] === null || $data['claude_api_key'] === '') {
                $user->claude_api_key = null;
                $updated['claude_api_key'] = 'removed';
            } else {
                // Validate Claude API key format
                if (!$this->validateClaudeApiKey($data['claude_api_key'])) {
                    return response()->json([
                        'error' => 'Invalid Claude API key format. It should start with "sk-ant-"',
                    ], 422);
                }
                $user->claude_api_key = $data['claude_api_key'];
                $updated['claude_api_key'] = 'updated';
            }
        }

        // Update OpenAI API key if provided
        if (array_key_exists('openai_api_key', $data)) {
            if ($data['openai_api_key'] === null || $data['openai_api_key'] === '') {
                $user->openai_api_key = null;
                $updated['openai_api_key'] = 'removed';
            } else {
                // Validate OpenAI API key format
                if (!$this->validateOpenaiApiKey($data['openai_api_key'])) {
                    return response()->json([
                        'error' => 'Invalid OpenAI API key format. It should start with "sk-"',
                    ], 422);
                }
                $user->openai_api_key = $data['openai_api_key'];
                $updated['openai_api_key'] = 'updated';
            }
        }

        // Update preferred provider
        if (isset($data['preferred_ai_provider'])) {
            $user->preferred_ai_provider = $data['preferred_ai_provider'];
            $updated['preferred_ai_provider'] = $data['preferred_ai_provider'];
        }

        // Update preferred OpenAI model
        if (array_key_exists('preferred_openai_model', $data)) {
            $user->preferred_openai_model = $data['preferred_openai_model'];
            $updated['preferred_openai_model'] = $data['preferred_openai_model'];
        }

        // Update preferred Claude model
        if (array_key_exists('preferred_claude_model', $data)) {
            $user->preferred_claude_model = $data['preferred_claude_model'];
            $updated['preferred_claude_model'] = $data['preferred_claude_model'];
        }

        $user->save();

        // Get full provider list with models
        $this->aiService->setUser($user);
        $providers = $this->aiService->getAvailableProviders();

        return response()->json([
            'success' => true,
            'message' => 'API settings updated successfully',
            'updated' => $updated,
            'claude_api_key_set' => $user->hasEffectiveClaudeApiKey(),
            'claude_api_key_masked' => $this->maskApiKey($user->getEffectiveClaudeApiKey()),
            'claude_api_key_from_env' => $user->isClaudeApiKeyFromEnv(),
            'openai_api_key_set' => $user->hasEffectiveOpenaiApiKey(),
            'openai_api_key_masked' => $this->maskApiKey($user->getEffectiveOpenaiApiKey()),
            'openai_api_key_from_env' => $user->isOpenaiApiKeyFromEnv(),
            'preferred_ai_provider' => $user->preferred_ai_provider,
            'preferred_openai_model' => $user->preferred_openai_model,
            'preferred_claude_model' => $user->preferred_claude_model,
            'available_providers' => $providers,
            'is_admin' => $user->is_admin,
        ]);
    }

    /**
     * Delete a specific API key
     *
     * @param Request $request
     * @param string $provider
     * @return JsonResponse
     */
    public function deleteApiKey(Request $request, string $provider): JsonResponse
    {
        $user = $request->attributes->get('user');

        if (!in_array($provider, ['claude', 'openai'])) {
            return response()->json([
                'error' => 'Invalid provider. Must be "claude" or "openai"',
            ], 400);
        }

        if ($provider === 'claude') {
            $user->claude_api_key = null;
        } else {
            $user->openai_api_key = null;
        }

        $user->save();

        // Get full provider list with models
        $this->aiService->setUser($user);
        $providers = $this->aiService->getAvailableProviders();

        return response()->json([
            'success' => true,
            'message' => ucfirst($provider) . ' API key removed successfully',
            'claude_api_key_set' => $user->hasEffectiveClaudeApiKey(),
            'claude_api_key_masked' => $this->maskApiKey($user->getEffectiveClaudeApiKey()),
            'claude_api_key_from_env' => $user->isClaudeApiKeyFromEnv(),
            'openai_api_key_set' => $user->hasEffectiveOpenaiApiKey(),
            'openai_api_key_masked' => $this->maskApiKey($user->getEffectiveOpenaiApiKey()),
            'openai_api_key_from_env' => $user->isOpenaiApiKeyFromEnv(),
            'preferred_ai_provider' => $user->preferred_ai_provider,
            'preferred_openai_model' => $user->preferred_openai_model,
            'preferred_claude_model' => $user->preferred_claude_model,
            'available_providers' => $providers,
            'is_admin' => $user->is_admin,
        ]);
    }

    /**
     * Mask API key for display (show first and last 4 chars)
     *
     * @param string|null $key
     * @return string|null
     */
    private function maskApiKey($key)
    {
        if ($key === null || strlen($key) < 12) {
            return null;
        }
        return substr($key, 0, 7) . '...' . substr($key, -4);
    }

    /**
     * Validate Claude API key format
     *
     * @param string $key
     * @return bool
     */
    private function validateClaudeApiKey(string $key): bool
    {
        return strpos($key, 'sk-ant-') === 0 && strlen($key) > 20;
    }

    /**
     * Validate OpenAI API key format
     *
     * @param string $key
     * @return bool
     */
    private function validateOpenaiApiKey(string $key): bool
    {
        return strpos($key, 'sk-') === 0 && strlen($key) > 20;
    }

    /**
     * Get current user's profile
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getProfile(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        return response()->json([
            'success' => true,
            'profile' => [
                'user_id' => $user->user_id,
                'username' => $user->username,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Update current user's profile (username, email)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $validator = Validator::make($request->all(), [
            'username' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
        ], [
            'username.required' => '表示名を入力してください',
            'username.max' => '表示名は255文字以内で入力してください',
            'email.email' => '有効なメールアドレスを入力してください',
            'email.max' => 'メールアドレスは255文字以内で入力してください',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $updated = [];

        if (array_key_exists('username', $data)) {
            $user->username = $data['username'];
            $updated['username'] = $data['username'];
        }

        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
            $updated['email'] = $data['email'];
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'プロフィールを更新しました',
            'updated' => $updated,
            'profile' => [
                'user_id' => $user->user_id,
                'username' => $user->username,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Get current user's research perspective settings
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getResearchPerspective(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        // Get defaults from config
        $defaults = [
            'research_fields' => config('surveymate.defaults.research_fields', ''),
            'summary_perspective' => config('surveymate.defaults.summary_perspective', ''),
            'reading_focus' => config('surveymate.defaults.reading_focus', ''),
        ];

        // Use user's settings if available, otherwise fall back to defaults
        if ($user->research_perspective) {
            $perspective = [
                'research_fields' => $user->research_perspective['research_fields'] ?? $defaults['research_fields'],
                'summary_perspective' => $user->research_perspective['summary_perspective'] ?? $defaults['summary_perspective'],
                'reading_focus' => $user->research_perspective['reading_focus'] ?? $defaults['reading_focus'],
            ];
        } else {
            $perspective = $defaults;
        }

        return response()->json([
            'success' => true,
            'research_perspective' => $perspective,
        ]);
    }

    /**
     * Get current user's summary template settings
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSummaryTemplate(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        // Get default from config
        $defaultTemplate = config('surveymate.defaults.summary_template', '');

        // Use user's template if set, otherwise use default
        $template = $user->summary_template ?? $defaultTemplate;

        return response()->json([
            'success' => true,
            'summary_template' => $template,
        ]);
    }

    /**
     * Update current user's summary template settings
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateSummaryTemplate(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $validator = Validator::make($request->all(), [
            'summary_template' => 'nullable|string|max:5000',
        ], [
            'summary_template.max' => '要約テンプレートは5000文字以内で入力してください',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $user->summary_template = $data['summary_template'] ?? null;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => '要約テンプレートを更新しました',
            'summary_template' => $user->summary_template,
        ]);
    }

    /**
     * Update current user's research perspective settings
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateResearchPerspective(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $validator = Validator::make($request->all(), [
            'research_fields' => 'nullable|string|max:2000',
            'summary_perspective' => 'nullable|string|max:2000',
            'reading_focus' => 'nullable|string|max:2000',
        ], [
            'research_fields.max' => '研究分野・興味は2000文字以内で入力してください',
            'summary_perspective.max' => '要約観点は2000文字以内で入力してください',
            'reading_focus.max' => '読解観点は2000文字以内で入力してください',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $user->research_perspective = [
            'research_fields' => $data['research_fields'] ?? '',
            'summary_perspective' => $data['summary_perspective'] ?? '',
            'reading_focus' => $data['reading_focus'] ?? '',
        ];
        $user->save();

        return response()->json([
            'success' => true,
            'message' => '調査観点設定を更新しました',
            'research_perspective' => $user->research_perspective,
        ]);
    }

    /**
     * Complete initial setup (save all settings at once)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function completeInitialSetup(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $validator = Validator::make($request->all(), [
            // AI API設定
            'claude_api_key' => 'nullable|string|max:500',
            'openai_api_key' => 'nullable|string|max:500',
            'preferred_ai_provider' => 'nullable|string|in:claude,openai',
            'preferred_openai_model' => 'nullable|string|max:100',
            'preferred_claude_model' => 'nullable|string|max:100',
            // 調査観点設定
            'research_fields' => 'nullable|string|max:2000',
            'summary_perspective' => 'nullable|string|max:2000',
            'reading_focus' => 'nullable|string|max:2000',
            // 要約テンプレート
            'summary_template' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // AI API設定の更新
        if (!empty($data['claude_api_key'])) {
            if ($this->validateClaudeApiKey($data['claude_api_key'])) {
                $user->claude_api_key = $data['claude_api_key'];
            }
        }
        if (!empty($data['openai_api_key'])) {
            if ($this->validateOpenaiApiKey($data['openai_api_key'])) {
                $user->openai_api_key = $data['openai_api_key'];
            }
        }
        if (!empty($data['preferred_ai_provider'])) {
            $user->preferred_ai_provider = $data['preferred_ai_provider'];
        }
        if (!empty($data['preferred_openai_model'])) {
            $user->preferred_openai_model = $data['preferred_openai_model'];
        }
        if (!empty($data['preferred_claude_model'])) {
            $user->preferred_claude_model = $data['preferred_claude_model'];
        }

        // 調査観点設定の更新
        $user->research_perspective = [
            'research_fields' => $data['research_fields'] ?? '',
            'summary_perspective' => $data['summary_perspective'] ?? '',
            'reading_focus' => $data['reading_focus'] ?? '',
        ];

        // 要約テンプレートの更新
        if (array_key_exists('summary_template', $data)) {
            $user->summary_template = $data['summary_template'];
        }

        // 初期設定完了フラグを設定
        $user->initial_setup_completed = true;
        $user->save();

        // 未取得の論文誌がある場合，バックグラウンドでRSSフェッチを開始
        $this->startBackgroundRssFetch($user->id);

        return response()->json([
            'success' => true,
            'message' => '初期設定が完了しました',
        ]);
    }

    /**
     * Skip initial setup
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function skipInitialSetup(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $user->initial_setup_completed = true;
        $user->save();

        // 未取得の論文誌がある場合，バックグラウンドでRSSフェッチを開始
        $this->startBackgroundRssFetch($user->id);

        return response()->json([
            'success' => true,
            'message' => '初期設定をスキップしました',
        ]);
    }

    /**
     * バックグラウンドでRSSフェッチを開始
     *
     * @param int $userId
     * @return void
     */
    private function startBackgroundRssFetch(int $userId): void
    {
        // 未取得の論文誌があるか確認
        $unfetchedCount = Journal::where('user_id', $userId)
            ->whereNull('last_fetched_at')
            ->active()
            ->count();

        if ($unfetchedCount === 0) {
            return;
        }

        // artisanコマンドをバックグラウンドで実行
        $artisanPath = base_path('artisan');
        $logPath = storage_path('logs/rss-fetch-background.log');

        // CLI用PHPパスを取得（環境変数またはwhichコマンドで検出）
        $phpBinary = $this->findCliPhpBinary();

        // Webサーバー環境でも動作するバックグラウンド実行
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            $command = sprintf(
                'start /B "%s" "%s" rss:fetch --user=%d >> "%s" 2>&1',
                $phpBinary,
                $artisanPath,
                $userId,
                $logPath
            );
            pclose(popen($command, 'r'));
        } else {
            // Unix系（バックグラウンド実行）
            $command = sprintf(
                '"%s" "%s" rss:fetch --user=%d >> "%s" 2>&1 &',
                $phpBinary,
                $artisanPath,
                $userId,
                $logPath
            );
            exec($command);
        }

        Log::info("バックグラウンドRSSフェッチを開始", [
            'user_id' => $userId,
            'unfetched_journals' => $unfetchedCount,
            'php_binary' => $phpBinary,
        ]);
    }

    /**
     * CLI用PHPバイナリのパスを取得
     *
     * @return string
     */
    private function findCliPhpBinary(): string
    {
        // 1. 環境変数で明示的に指定されている場合
        $envPhp = env('PHP_CLI_PATH');
        if ($envPhp && is_executable($envPhp)) {
            return $envPhp;
        }

        // 2. 一般的なCLI PHPパスを検索
        $candidates = [
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
            '/Applications/MAMP/bin/php/php8.3.28/bin/php',
            '/Applications/MAMP/bin/php/php8.2.20/bin/php',
            '/Applications/MAMP/bin/php/php8.1.28/bin/php',
        ];

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        // 3. whichコマンドで検索
        $which = trim(shell_exec('which php 2>/dev/null') ?? '');
        if ($which && is_executable($which)) {
            return $which;
        }

        // 4. フォールバック
        return PHP_BINARY;
    }
}
