<?php

namespace App\Http\Controllers;

use App\Models\Journal;
use App\Models\Session;
use App\Models\User;
use App\Services\HcimlabSsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use RuntimeException;

/**
 * HCIMLab SSO（OIDC Authorization Code + PKCE）によるログイン．
 *
 * IdP の sub（不変ID）を users.sso_sub に保持して紐付ける．
 * 初回ログイン時にユーザーが存在しなければ，IdP の user_id を
 * 本システムの user_id として自動登録する．
 */
class SsoController extends Controller
{
    public function __construct(private HcimlabSsoService $sso)
    {
    }

    /**
     * SSO が有効かどうか（ログイン画面のボタン表示用）
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'enabled' => $this->sso->isEnabled(),
            'label' => $this->sso->buttonLabel(),
        ]);
    }

    /**
     * IdP の認可エンドポイントへリダイレクト
     */
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($this->sso->isEnabled(), 404);

        try {
            $provider = $this->sso->provider();
            $nonce = Str::random(40);

            // nonce は追加パラメータとして認可リクエストに含める（リプレイ対策）
            $authUrl = $provider->getAuthorizationUrl(['nonce' => $nonce]);

            $request->session()->put([
                'sso.state' => $provider->getState(),
                'sso.pkce' => $provider->getPkceCode(),
                'sso.nonce' => $nonce,
            ]);

            // state/code を含む認可フローのレスポンスはどこにもキャッシュさせない
            return $this->noStore(redirect()->away($authUrl));
        } catch (\Throwable $e) {
            Log::error('SSO リダイレクトエラー: ' . $e->getMessage());
            return $this->failLogin('SSO認証サーバに接続できませんでした');
        }
    }

    /**
     * IdP からのコールバック（認可コード受け取り〜ログイン確立）
     */
    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->sso->isEnabled(), 404);

        // IdP 側でのエラー（アクセス拒否等）
        if ($request->filled('error')) {
            Log::warning('SSO コールバックエラー: ' . $request->input('error'), [
                'description' => $request->input('error_description'),
            ]);
            return $this->failLogin('SSO認証がキャンセルまたは拒否されました');
        }

        // 検証値は pull（取り出して削除）で一度きり使用する（コードの二重交換防止）
        $state = $request->session()->pull('sso.state');
        $pkce = $request->session()->pull('sso.pkce');
        $nonce = $request->session()->pull('sso.nonce');

        // 保留中の state が無い＝このセッションが開始したログインフローではない
        // （消費済み callback URL の再訪・リロード・「戻る」等）．
        // 認可コードは一度交換すると失効するため，交換を試みずに打ち切る．
        if (!$state) {
            Log::info('SSO コールバック再訪を検出（保留中の state なし）', [
                'has_code' => $request->filled('code'),
            ]);
            if ($this->hasValidAppSession($request)) {
                return $this->noStore(redirect('/papers'));
            }
            return $this->failLogin('ログインの有効期限が切れました．もう一度ログインしてください');
        }

        // state 検証（CSRF対策）
        if (!$request->filled('code') || $request->input('state') !== $state) {
            return $this->failLogin('SSO認証の検証に失敗しました（state不一致）．もう一度お試しください');
        }

        try {
            $provider = $this->sso->provider();
            $provider->setPkceCode($pkce);

            $token = $provider->getAccessToken('authorization_code', [
                'code' => $request->input('code'),
            ]);

            // id_token を JWKS で署名検証（iss / aud / exp / nonce）
            $idToken = $token->getValues()['id_token'] ?? null;
            if (!$idToken) {
                throw new RuntimeException('トークンレスポンスに id_token が含まれていません');
            }
            $idClaims = $this->sso->verifyIdToken($idToken, (string) $nonce);

            // UserInfo から詳細クレーム（user_id / role 等）を取得し，sub の一致を確認
            $claims = $provider->getResourceOwner($token)->toArray();
            if (($claims['sub'] ?? null) !== $idClaims['sub']) {
                throw new RuntimeException('UserInfo と id_token の sub が一致しません');
            }

            // IdP 側で無効化されたユーザーは弾く（二重チェック）
            if (array_key_exists('is_active', $claims) && !$claims['is_active']) {
                return $this->failLogin('アカウントが認証基盤側で無効化されています');
            }

            $user = $this->upsertUser($claims);

            if (!$user->is_active) {
                return $this->failLogin('アカウントが無効化されています');
            }

            $user->update(['last_login_at' => now()]);

            // 通常ログインと同一のセッション方式でログイン確立
            $sessionLifetime = config('session.lifetime', 1440) * 60;
            $session = Session::createForUser($user, $sessionLifetime);

            // ログイン成功後は必ず固定パスへ（intended 等の「戻り先」は使わない．
            // 過去の code 付き callback URL へ戻ると invalid_grant で必ず失敗する）
            $redirectTo = $user->initial_setup_completed ? '/papers' : '/initial-setup';

            return $this->noStore(redirect($redirectTo))
                ->cookie('session_id', $session->id, $sessionLifetime / 60, '/', null, false, true);
        } catch (IdentityProviderException $e) {
            // 消費済みコードの再交換（invalid_grant）等，IdP のトークンエンドポイント拒否
            Log::error('SSO トークン交換エラー: ' . $e->getMessage(), [
                'response' => $e->getResponseBody(),
            ]);
            return $this->failLogin('ログインの有効期限が切れました．もう一度ログインしてください');
        } catch (\Throwable $e) {
            Log::error('SSO ログインエラー: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->failLogin('SSOログイン処理中にエラーが発生しました');
        }
    }

    /**
     * 本システムのセッション（session_id クッキー）が有効かどうか
     */
    private function hasValidAppSession(Request $request): bool
    {
        $sessionId = $request->cookie('session_id');
        if (!$sessionId) {
            return false;
        }

        $session = Session::find($sessionId);

        return $session !== null && !$session->isExpired();
    }

    /**
     * sub をキーにユーザーを検索・紐付け・作成する
     */
    private function upsertUser(array $claims): User
    {
        $sub = (string) $claims['sub'];

        $user = User::where('sso_sub', $sub)->first();
        if ($user) {
            // メールが未設定なら IdP の値で補完
            if (empty($user->email) && !empty($claims['email'])) {
                $user->update(['email' => $claims['email']]);
            }
            return $user;
        }

        $userId = $this->resolveUserId($claims, $sub);

        // 同じ user_id のローカルアカウントが既にあれば SSO を紐付ける
        // （研究室IDが同一＝同一人物とみなす）
        $existing = User::where('user_id', $userId)->first();
        if ($existing) {
            if ($existing->sso_sub === null) {
                $existing->update(['sso_sub' => $sub]);
                return $existing;
            }
            // 別の sub が既に紐付いている場合は衝突を避けて新規IDを採番
            $userId = $this->uniqueUserId($userId);
        }

        $user = User::create([
            'user_id' => $userId,
            'sso_sub' => $sub,
            'username' => $claims['name'] ?? $claims['username'] ?? $claims['preferred_username'] ?? $userId,
            // SSO 経由ユーザーにはランダムパスワードを設定（パスワードログインは実質無効）
            'password' => Str::random(40),
            'email' => $claims['email'] ?? null,
            'is_admin' => false,
            'is_active' => true,
        ]);

        try {
            Journal::createDefaultsForUser($user);
        } catch (\Exception $e) {
            Log::warning('デフォルト論文誌の作成に失敗: ' . $e->getMessage());
        }

        Log::info("SSO 経由で新規ユーザーを作成しました: {$userId} (sub={$sub})");

        return $user;
    }

    /**
     * IdP のクレームから本システムの user_id を決定する
     * （user_id クレーム優先．ユーザIDを認証基盤と同一に揃える）
     */
    private function resolveUserId(array $claims, string $sub): string
    {
        $candidate = $claims['user_id']
            ?? $claims['preferred_username']
            ?? $claims['username']
            ?? ('sso_' . $sub);

        // 本システムの user_id 制約（英数字・アンダースコア・ハイフン，3〜50文字）に合わせる
        $candidate = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $candidate);
        $candidate = substr($candidate, 0, 50);

        if (strlen($candidate) < 3) {
            $candidate = substr('sso_' . $sub . '_' . $candidate, 0, 50);
        }

        return $candidate;
    }

    /**
     * user_id が衝突した場合にサフィックスを付けて一意なIDを生成する
     */
    private function uniqueUserId(string $base): string
    {
        for ($i = 2; $i < 100; $i++) {
            $candidate = substr($base, 0, 50 - strlen("_{$i}")) . "_{$i}";
            if (!User::where('user_id', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException("一意なユーザーIDを生成できませんでした: {$base}");
    }

    /**
     * エラーメッセージ付きでログイン画面へ戻す（500 にはしない）
     */
    private function failLogin(string $message): RedirectResponse
    {
        return $this->noStore(redirect('/login?sso_error=' . urlencode($message)));
    }

    /**
     * 認可フロー中のレスポンスをブラウザ・SW・中間キャッシュに保存させない
     * （code/state 付きレスポンスの再生はコード二重交換＝invalid_grant の原因になる）
     */
    private function noStore(RedirectResponse $response): RedirectResponse
    {
        return $response->header('Cache-Control', 'no-store, max-age=0');
    }
}
