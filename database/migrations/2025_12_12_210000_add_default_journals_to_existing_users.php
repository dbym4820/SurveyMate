<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Journal;
use App\Services\RssFetcherService;

return new class extends Migration
{
    public function up(): void
    {
        $defaultJournals = config('surveymate.default_journals', []);

        if (empty($defaultJournals)) {
            return;
        }

        $users = User::all();
        $rssFetcherService = app(RssFetcherService::class);

        foreach ($users as $user) {
            foreach ($defaultJournals as $journalConfig) {
                $name = $journalConfig['name'];
                $baseId = $journalConfig['id'] ?? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
                $journalId = $baseId . '-' . $user->id;

                // 既に同じIDの論文誌が存在する場合はスキップ
                if (Journal::where('id', $journalId)->exists()) {
                    Log::info("Journal {$journalId} already exists for user {$user->user_id}, skipping");
                    continue;
                }

                // 同じ名前の論文誌が存在する場合もスキップ
                if (Journal::where('user_id', $user->id)->where('name', $name)->exists()) {
                    Log::info("Journal {$name} already exists for user {$user->user_id}, skipping");
                    continue;
                }

                $journal = Journal::create([
                    'id' => $journalId,
                    'user_id' => $user->id,
                    'name' => $name,
                    'full_name' => $journalConfig['full_name'] ?? null,
                    'rss_url' => $journalConfig['rss_url'],
                    'color' => $journalConfig['color'] ?? 'bg-gray-500',
                    'is_active' => true,
                ]);

                Log::info("Created journal {$journal->name} for user {$user->user_id}");

                // 初回RSS取得を実行
                try {
                    $result = $rssFetcherService->fetchJournal($journal);
                    Log::info("Fetched RSS for {$journal->name}: " . json_encode($result));
                } catch (\Exception $e) {
                    Log::warning("初回RSS取得に失敗: {$journal->name}", ['error' => $e->getMessage()]);
                }
            }
        }
    }

    public function down(): void
    {
        // デフォルト論文誌の削除（オプション）
        // 注意: この操作は取得した論文も削除する可能性がある
        $defaultJournals = config('surveymate.default_journals', []);

        foreach ($defaultJournals as $journalConfig) {
            $baseId = $journalConfig['id'] ?? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $journalConfig['name']));
            // パターンマッチで削除（例: ijaied-1, ijaied-2 など）
            Journal::where('id', 'like', $baseId . '-%')->delete();
        }
    }
};
