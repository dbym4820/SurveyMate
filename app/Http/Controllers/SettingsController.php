<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    /**
     * Get current user's API settings (without revealing full keys)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getApiSettings(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        return response()->json([
            'success' => true,
            'claude_api_key_set' => $user->hasClaudeApiKey(),
            'claude_api_key_masked' => $this->maskApiKey($user->claude_api_key),
            'openai_api_key_set' => $user->hasOpenaiApiKey(),
            'openai_api_key_masked' => $this->maskApiKey($user->openai_api_key),
            'preferred_ai_provider' => $user->preferred_ai_provider,
            'preferred_openai_model' => $user->preferred_openai_model,
            'preferred_claude_model' => $user->preferred_claude_model,
            'available_providers' => $user->getAvailableAiProviders(),
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

        return response()->json([
            'success' => true,
            'message' => 'API settings updated successfully',
            'updated' => $updated,
            'claude_api_key_set' => $user->hasClaudeApiKey(),
            'openai_api_key_set' => $user->hasOpenaiApiKey(),
            'preferred_ai_provider' => $user->preferred_ai_provider,
            'preferred_openai_model' => $user->preferred_openai_model,
            'preferred_claude_model' => $user->preferred_claude_model,
            'available_providers' => $user->getAvailableAiProviders(),
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

        return response()->json([
            'success' => true,
            'message' => ucfirst($provider) . ' API key removed successfully',
            'claude_api_key_set' => $user->hasClaudeApiKey(),
            'openai_api_key_set' => $user->hasOpenaiApiKey(),
            'preferred_ai_provider' => $user->preferred_ai_provider,
            'preferred_openai_model' => $user->preferred_openai_model,
            'preferred_claude_model' => $user->preferred_claude_model,
            'available_providers' => $user->getAvailableAiProviders(),
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

        $perspective = $user->research_perspective ?? [
            'research_fields' => '',
            'summary_perspective' => '',
            'reading_focus' => '',
        ];

        return response()->json([
            'success' => true,
            'research_perspective' => $perspective,
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
}
