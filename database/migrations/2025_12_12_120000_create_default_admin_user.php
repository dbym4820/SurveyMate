<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * .envで指定された管理者ユーザーを作成
     * ADMIN_CLAUDE_API_KEY / ADMIN_OPENAI_API_KEY が設定されていれば
     * 管理者ユーザーのAPIキーとして自動設定
     * DEFAULT_* で調査観点や要約テンプレートも設定
     */
    public function up(): void
    {
        $adminUserId = env('ADMIN_USER_ID');
        $adminUsername = env('ADMIN_USERNAME');
        $adminPassword = env('ADMIN_PASSWORD');
        $adminEmail = env('ADMIN_EMAIL');

        // 必須項目が設定されていない場合はスキップ
        if (empty($adminUserId) || empty($adminUsername) || empty($adminPassword)) {
            return;
        }

        // APIキーを取得（設定されていれば暗号化して保存）
        $claudeApiKey = env('ADMIN_CLAUDE_API_KEY');
        $openaiApiKey = env('ADMIN_OPENAI_API_KEY');

        // デフォルトの調査観点・要約テンプレートを取得
        $researchFields = env('DEFAULT_RESEARCH_FIELDS', '');
        $summaryPerspective = env('DEFAULT_SUMMARY_PERSPECTIVE', '');
        $readingFocus = env('DEFAULT_READING_FOCUS', '');
        $summaryTemplate = env('DEFAULT_SUMMARY_TEMPLATE', '');

        $userData = [
            'user_id' => $adminUserId,
            'username' => $adminUsername,
            'password_hash' => Hash::make($adminPassword),
            'email' => $adminEmail,
            'is_admin' => true,
            'is_active' => true,
            'initial_setup_completed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // APIキーが設定されていれば暗号化して追加
        if (!empty($claudeApiKey)) {
            $userData['claude_api_key'] = Crypt::encryptString($claudeApiKey);
            $userData['preferred_ai_provider'] = 'claude';
        }
        if (!empty($openaiApiKey)) {
            $userData['openai_api_key'] = Crypt::encryptString($openaiApiKey);
            // ClaudeのAPIキーがなければOpenAIを優先プロバイダに
            if (empty($claudeApiKey)) {
                $userData['preferred_ai_provider'] = 'openai';
            }
        }

        // 調査観点設定（いずれかが設定されていれば保存）
        if (!empty($researchFields) || !empty($summaryPerspective) || !empty($readingFocus)) {
            $userData['research_perspective'] = json_encode([
                'research_fields' => $researchFields,
                'summary_perspective' => $summaryPerspective,
                'reading_focus' => $readingFocus,
            ]);
        }

        // 要約テンプレート
        if (!empty($summaryTemplate)) {
            $userData['summary_template'] = $summaryTemplate;
        }

        // 既存ユーザーがいなければ作成
        $exists = DB::table('users')->where('user_id', $adminUserId)->exists();
        if (!$exists) {
            DB::table('users')->insert($userData);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $adminUserId = env('ADMIN_USER_ID');
        if (!empty($adminUserId)) {
            DB::table('users')->where('user_id', $adminUserId)->delete();
        }
    }
};
