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
    protected $signature = 'admin:sync-api-keys';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '.envのADMIN_CLAUDE_API_KEY/ADMIN_OPENAI_API_KEYを管理者ユーザーに同期';

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

        $claudeApiKey = env('ADMIN_CLAUDE_API_KEY');
        $openaiApiKey = env('ADMIN_OPENAI_API_KEY');

        if (empty($claudeApiKey) && empty($openaiApiKey)) {
            $this->warn('ADMIN_CLAUDE_API_KEY / ADMIN_OPENAI_API_KEY が設定されていません');
            return Command::SUCCESS;
        }

        $updated = [];

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

        $admin->save();

        $this->info('管理者ユーザーに以下のAPIキーを設定しました: ' . implode(', ', $updated));
        return Command::SUCCESS;
    }
}
