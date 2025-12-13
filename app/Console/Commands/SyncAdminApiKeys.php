<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Journal;
use App\Services\RssFetcherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncAdminApiKeys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:sync-settings {--keys-only : APIキーのみ同期} {--skip-journals : デフォルトジャーナル設定をスキップ}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '.envの管理者設定（APIキー，調査観点，要約テンプレート，デフォルトジャーナル）を管理者ユーザーに同期';

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

        if (!empty($updated)) {
            $admin->save();
            $this->info('管理者ユーザーに以下の設定を同期しました: ' . implode(', ', $updated));
        } else {
            $this->warn('.env に同期する設定が見つかりませんでした');
        }

        // デフォルトジャーナルの設定
        if (!$this->option('keys-only') && !$this->option('skip-journals')) {
            $journalResult = $this->syncDefaultJournals($admin);
            if ($journalResult > 0) {
                $this->info("デフォルトジャーナル {$journalResult} 件を設定しました");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * 管理者ユーザーにデフォルトジャーナルを設定
     */
    private function syncDefaultJournals(User $admin): int
    {
        $defaultJournals = config('surveymate.default_journals', []);

        if (empty($defaultJournals)) {
            $this->line('デフォルトジャーナルは設定されていません（DEFAULT_JOURNALS 環境変数）');
            return 0;
        }

        $rssFetcherService = app(RssFetcherService::class);
        $createdCount = 0;

        foreach ($defaultJournals as $journalConfig) {
            $name = $journalConfig['name'];

            // 同名ジャーナルの重複チェック（nameベース）
            if (Journal::where('user_id', $admin->id)->where('name', $name)->exists()) {
                $this->line("  スキップ（既存）: {$name}");
                continue;
            }

            $journal = Journal::create([
                'user_id' => $admin->id,
                'name' => $name,
                'rss_url' => $journalConfig['rss_url'],
                'color' => $journalConfig['color'] ?? 'bg-gray-500',
                'is_active' => true,
            ]);

            $this->line("  作成: {$name}");
            Log::info("Created journal {$journal->name} for admin user {$admin->user_id}");
            $createdCount++;

            // 初回RSS取得を実行
            try {
                $result = $rssFetcherService->fetchJournal($journal);
                $fetchedCount = $result['new'] ?? 0;
                $this->line("    RSS取得: {$fetchedCount} 件の新規論文");
                Log::info("Fetched RSS for {$journal->name}: " . json_encode($result));
            } catch (\Exception $e) {
                $this->warn("    RSS取得失敗: " . $e->getMessage());
                Log::warning("初回RSS取得に失敗: {$journal->name}", ['error' => $e->getMessage()]);
            }
        }

        return $createdCount;
    }
}
