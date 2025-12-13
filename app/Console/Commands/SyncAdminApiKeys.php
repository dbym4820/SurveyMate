<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SyncAdminApiKeys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:sync-settings {--keys-only : APIキーのみ同期}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '.envの管理者設定（APIキー，調査観点，要約テンプレート）を管理者ユーザーに同期';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $adminUserId = env('ADMIN_USER_ID');
        if (empty($adminUserId)) {
            $this->error('ADMIN_USER_ID が .env に設定されていません');
            return Command::FAILURE;
        }

        $admin = User::where('user_id', $adminUserId)->first();
        if (!$admin) {
            $this->error("管理者ユーザー '{$adminUserId}' が見つかりません");
            return Command::FAILURE;
        }

        $updated = [];

        // APIキーの同期
        $claudeApiKey = env('ADMIN_CLAUDE_API_KEY');
        $openaiApiKey = env('ADMIN_OPENAI_API_KEY');

        if (!empty($claudeApiKey)) {
            $admin->claude_api_key = $claudeApiKey;
            $updated[] = 'Claude API key';
            if (!$admin->preferred_ai_provider) {
                $admin->preferred_ai_provider = 'claude';
            }
        }

        if (!empty($openaiApiKey)) {
            $admin->openai_api_key = $openaiApiKey;
            $updated[] = 'OpenAI API key';
            if (!$admin->preferred_ai_provider) {
                $admin->preferred_ai_provider = 'openai';
            }
        }

        // --keys-only オプションが指定されていなければ，調査観点と要約テンプレートも同期
        if (!$this->option('keys-only')) {
            $researchFields = env('DEFAULT_RESEARCH_FIELDS', '');
            $summaryPerspective = env('DEFAULT_SUMMARY_PERSPECTIVE', '');
            $readingFocus = env('DEFAULT_READING_FOCUS', '');
            $summaryTemplate = env('DEFAULT_SUMMARY_TEMPLATE', '');

            // 調査観点設定
            if (!empty($researchFields) || !empty($summaryPerspective) || !empty($readingFocus)) {
                $admin->research_perspective = [
                    'research_fields' => $researchFields,
                    'summary_perspective' => $summaryPerspective,
                    'reading_focus' => $readingFocus,
                ];
                $updated[] = '調査観点設定';
            }

            // 要約テンプレート
            if (!empty($summaryTemplate)) {
                $admin->summary_template = $summaryTemplate;
                $updated[] = '要約テンプレート';
            }
        }

        if (empty($updated)) {
            $this->warn('.env に同期する設定が見つかりませんでした');
            return Command::SUCCESS;
        }

        $admin->save();

        $this->info('管理者ユーザーに以下の設定を同期しました: ' . implode(', ', $updated));
        return Command::SUCCESS;
    }
}
