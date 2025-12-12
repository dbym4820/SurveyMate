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
}
