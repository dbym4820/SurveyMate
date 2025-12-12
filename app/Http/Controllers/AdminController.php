<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\Journal;
use App\Models\FetchLog;
use App\Models\User;
use App\Services\RssFetcherService;

class AdminController extends Controller
{
    /** @var RssFetcherService */
    private $rssFetcher;

    public function __construct(RssFetcherService $rssFetcher)
    {
        $this->rssFetcher = $rssFetcher;
    }

    // ======================================
    // Scheduler
    // ======================================

    public function schedulerStatus(): JsonResponse
    {
        return response()->json($this->rssFetcher->getStatus());
    }

    public function schedulerRun(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $journalId = $request->journalId;

        if ($journalId) {
            // 管理者は全ジャーナル，一般ユーザーは自分のジャーナルのみ
            if ($user->is_admin) {
                $journal = Journal::find($journalId);
            } else {
                $journal = Journal::forUser($user->id)->find($journalId);
            }
            if (!$journal) {
                return response()->json(['error' => '論文誌が見つかりません'], 404);
            }
            $result = $this->rssFetcher->fetchJournal($journal);
        } else {
            // 管理者は全ジャーナル，一般ユーザーは自分のジャーナルのみ
            if ($user->is_admin) {
                $result = $this->rssFetcher->fetchAll();
            } else {
                $result = $this->rssFetcher->fetchForUser($user->id);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'RSS取得を実行しました',
            'result' => $result,
        ]);
    }

    // ======================================
    // Fetch Logs
    // ======================================

    public function logs(Request $request): JsonResponse
    {
        $limit = min((int) ($request->limit ?? 50), 100);

        $logs = FetchLog::with('journal:id,name')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'journal_id' => $log->journal_id,
                    'journal_name' => $log->journal ? $log->journal->name : null,
                    'status' => $log->status,
                    'papers_fetched' => $log->papers_fetched,
                    'new_papers' => $log->new_papers,
                    'error_message' => $log->error_message,
                    'execution_time_ms' => $log->execution_time_ms,
                    'created_at' => $log->created_at ? $log->created_at->toISOString() : null,
                ];
            });

        return response()->json([
            'success' => true,
            'logs' => $logs,
        ]);
    }

    // ======================================
    // Journals Management
    // ======================================

    public function createJournal(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        // Convert camelCase to snake_case for compatibility
        $data = $request->all();
        if (isset($data['rssUrl']) && !isset($data['rss_url'])) {
            $data['rss_url'] = $data['rssUrl'];
        }

        $validator = Validator::make($data, [
            'id' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'full_name' => 'nullable|string|max:500',
            'rss_url' => 'required|url|max:500',
            'color' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        // IDの重複チェック（ユーザーごと）
        $existingJournal = Journal::forUser($user->id)->where('id', $data['id'])->first();
        if ($existingJournal) {
            return response()->json(['error' => 'この論文誌IDは既に使用されています'], 400);
        }

        $journalData = $validator->validated();
        $journalData['user_id'] = $user->id;

        $journal = Journal::create($journalData);

        // 初回RSSフェッチを実行
        $fetchResult = $this->rssFetcher->fetchJournal($journal);

        return response()->json([
            'success' => true,
            'message' => '論文誌を追加しました',
            'journal' => $journal,
            'fetch_result' => $fetchResult,
        ], 201);
    }

    public function updateJournal(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('user');

        $journal = Journal::forUser($user->id)->find($id);

        if (!$journal) {
            return response()->json(['error' => '論文誌が見つかりません'], 404);
        }

        // Convert camelCase to snake_case for compatibility
        $data = $request->all();
        if (isset($data['rssUrl']) && !isset($data['rss_url'])) {
            $data['rss_url'] = $data['rssUrl'];
        }

        $validator = Validator::make($data, [
            'name' => 'nullable|string|max:255',
            'full_name' => 'nullable|string|max:500',
            'rss_url' => 'nullable|url|max:500',
            'color' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        $journal->update(array_filter($validator->validated(), function ($v) {
            return $v !== null;
        }));

        return response()->json([
            'success' => true,
            'message' => '論文誌を更新しました',
            'journal' => $journal,
        ]);
    }

    public function deleteJournal(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('user');

        $journal = Journal::forUser($user->id)->find($id);

        if (!$journal) {
            return response()->json(['error' => '論文誌が見つかりません'], 404);
        }

        // Soft delete by deactivating
        $journal->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => '論文誌を無効化しました',
        ]);
    }

    public function activateJournal(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('user');

        $journal = Journal::forUser($user->id)->find($id);

        if (!$journal) {
            return response()->json(['error' => '論文誌が見つかりません'], 404);
        }

        $journal->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => '論文誌を有効化しました',
            'journal' => $journal,
        ]);
    }

    public function testRss(Request $request): JsonResponse
    {
        // Convert camelCase to snake_case for compatibility
        $data = $request->all();
        if (isset($data['rssUrl']) && !isset($data['rss_url'])) {
            $data['rss_url'] = $data['rssUrl'];
        }

        $validator = Validator::make($data, [
            'rss_url' => 'required|url',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        try {
            $result = $this->rssFetcher->testFeed($data['rss_url']);
            return response()->json([
                'success' => true,
                'feed_title' => $result['title'],
                'item_count' => $result['item_count'],
                'sample_items' => $result['sample_items'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'RSSフィードの取得に失敗しました: ' . $e->getMessage(),
            ], 400);
        }
    }

    public function fetchJournal(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('user');

        $journal = Journal::forUser($user->id)->find($id);

        if (!$journal) {
            return response()->json(['error' => '論文誌が見つかりません'], 404);
        }

        $result = $this->rssFetcher->fetchJournal($journal);

        return response()->json([
            'success' => true,
            'message' => 'RSS取得を実行しました',
            'result' => $result,
        ]);
    }

    // ======================================
    // Users Management
    // ======================================

    public function users(): JsonResponse
    {
        $users = User::orderBy('created_at', 'desc')
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'username' => $u->username,
                    'email' => $u->email,
                    'is_admin' => $u->is_admin,
                    'is_active' => $u->is_active,
                    'last_login_at' => $u->last_login_at ? $u->last_login_at->toISOString() : null,
                    'created_at' => $u->created_at ? $u->created_at->toISOString() : null,
                ];
            });

        return response()->json([
            'success' => true,
            'users' => $users,
        ]);
    }
}
