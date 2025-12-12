<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Session;
use App\Models\Journal;
use App\Services\RssFetcherService;

class AuthController extends Controller
{
    public function __construct(
        private RssFetcherService $rssFetcherService
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'ユーザーIDとパスワードは必須です'], 400);
        }

        // user_id でユーザーを検索
        $user = User::where('user_id', $request->user_id)->first();

        if (!$user || !$user->verifyPassword($request->password)) {
            return response()->json(['error' => 'ユーザーIDまたはパスワードが正しくありません'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['error' => 'アカウントが無効化されています'], 401);
        }

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Create session
        $sessionLifetime = config('session.lifetime', 1440) * 60; // Convert minutes to seconds
        $session = Session::createForUser($user, $sessionLifetime);

        return response()->json([
            'success' => true,
            'message' => 'ログインに成功しました',
            'user' => [
                'id' => $user->id,
                'userId' => $user->user_id,
                'username' => $user->username,
                'email' => $user->email,
                'isAdmin' => $user->is_admin,
            ],
            'expiresAt' => now()->addSeconds($sessionLifetime)->toISOString(),
        ])->cookie('session_id', $session->id, $sessionLifetime / 60, '/', null, false, true);
    }

    public function logout(Request $request): JsonResponse
    {
        $session = $request->attributes->get('session');

        if ($session) {
            $session->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'ログアウトしました',
        ])->cookie('session_id', '', -1);
    }

    public function register(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|string|min:3|max:50|regex:/^[a-zA-Z0-9_-]+$/|unique:users,user_id',
                'username' => 'required|string|min:1|max:100',  // 表示名は重複可
                'password' => 'required|string|min:6',
                'email' => 'nullable|email|max:255',
            ], [
                'user_id.regex' => 'ユーザーIDは英数字，アンダースコア，ハイフンのみ使用できます',
                'user_id.unique' => 'このユーザーIDは既に使用されています',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()->first()], 400);
            }

            $user = User::create([
                'user_id' => $request->user_id,
                'username' => $request->username,
                'password' => $request->password, // Will be hashed by mutator
                'email' => $request->email,
                'is_admin' => false,
                'is_active' => true,
            ]);

            // デフォルト論文誌を作成（失敗してもユーザー登録は継続）
            try {
                $this->createDefaultJournals($user);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("デフォルト論文誌の作成に失敗: " . $e->getMessage());
            }

            // 自動ログイン: セッション作成
            $user->update(['last_login_at' => now()]);
            $sessionLifetime = config('session.lifetime', 1440) * 60;
            $session = Session::createForUser($user, $sessionLifetime);

            return response()->json([
                'success' => true,
                'message' => 'ユーザーを作成しました',
                'user' => [
                    'id' => $user->id,
                    'userId' => $user->user_id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'isAdmin' => $user->is_admin,
                ],
                'expiresAt' => now()->addSeconds($sessionLifetime)->toISOString(),
            ], 201)->cookie('session_id', $session->id, $sessionLifetime / 60, '/', null, false, true);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("ユーザー登録エラー: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => '登録処理中にエラーが発生しました: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function me(Request $request): JsonResponse
    {
        // セッションIDを取得（Cookie または Authorizationヘッダー）
        $sessionId = $request->cookie('session_id');
        if (!$sessionId) {
            $authHeader = $request->header('Authorization');
            if ($authHeader && strpos($authHeader, 'Bearer ') === 0) {
                $sessionId = substr($authHeader, 7);
            }
        }

        // セッションがない場合
        if (!$sessionId) {
            return response()->json([
                'success' => true,
                'authenticated' => false,
                'user' => null,
            ]);
        }

        // セッションを検証
        $session = Session::with('user')->find($sessionId);

        if (!$session || $session->isExpired() || !$session->user?->is_active) {
            if ($session?->isExpired()) {
                $session->delete();
            }
            return response()->json([
                'success' => true,
                'authenticated' => false,
                'user' => null,
            ]);
        }

        $user = $session->user;

        return response()->json([
            'success' => true,
            'authenticated' => true,
            'user' => [
                'id' => $user->id,
                'userId' => $user->user_id,
                'username' => $user->username,
                'email' => $user->email,
                'isAdmin' => $user->is_admin,
                'last_login_at' => $user->last_login_at,
            ],
        ]);
    }

    /**
     * 新規ユーザーにデフォルトの論文誌を作成し，初回RSS取得を実行
     */
    private function createDefaultJournals(User $user): void
    {
        $defaultJournals = config('surveymate.default_journals', []);

        foreach ($defaultJournals as $journalConfig) {
            $name = $journalConfig['name'];
            // IDは正式名称から自動生成（英数字のみ，小文字，ユーザーID付加）
            $journalId = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name)) . '-' . $user->id;

            $journal = Journal::create([
                'id' => $journalId,
                'user_id' => $user->id,
                'name' => $name,
                'rss_url' => $journalConfig['rss_url'],
                'color' => $journalConfig['color'] ?? 'bg-gray-500',
                'is_active' => true,
            ]);

            // 初回RSS取得を実行（バックグラウンドで非同期処理が望ましいが，初回なので同期で実行）
            try {
                $this->rssFetcherService->fetchJournal($journal);
            } catch (\Exception $e) {
                // 初回取得に失敗しても登録は継続
                \Illuminate\Support\Facades\Log::warning("初回RSS取得に失敗: {$journal->name}", ['error' => $e->getMessage()]);
            }
        }
    }
}
