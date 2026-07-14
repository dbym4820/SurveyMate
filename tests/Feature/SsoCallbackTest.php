<?php

namespace Tests\Feature;

use App\Models\Session;
use App\Models\User;
use App\Services\HcimlabSsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Token\AccessToken;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * SSO コールバックの再処理防止（消費済み認可コードの二重交換防止）のテスト．
 *
 * バグの本体: 1回目のログインは成功するが，消費済みの古い
 * callback URL（?code=...&state=...）が再処理されると，IdP に失効済み
 * コードが送られ invalid_grant（Authorization code has been revoked）になる．
 * ここでは「保留中の state が無ければ絶対にトークン交換しない」ことを検証する．
 */
class SsoCallbackTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $provider;

    /**
     * HcimlabSsoService をモックし，トークン交換の呼び出し回数を検証可能にする
     */
    private function mockSso(array $claims): void
    {
        $this->provider = Mockery::mock(GenericProvider::class);
        $this->provider->shouldReceive('setPkceCode');
        $this->provider->shouldReceive('getResourceOwner')
            ->andReturn(new GenericResourceOwner($claims, 'sub'));

        $sso = Mockery::mock(HcimlabSsoService::class);
        $sso->shouldReceive('isEnabled')->andReturn(true);
        $sso->shouldReceive('provider')->andReturn($this->provider);
        $sso->shouldReceive('verifyIdToken')->andReturn(['sub' => $claims['sub']]);

        $this->app->instance(HcimlabSsoService::class, $sso);
    }

    private function defaultClaims(): array
    {
        return [
            'sub' => 'idp-sub-0001',
            'user_id' => 'tomoki',
            'name' => 'Tomoki',
            'is_active' => true,
        ];
    }

    private function token(): AccessToken
    {
        return new AccessToken(['access_token' => 'at', 'id_token' => 'dummy-id-token']);
    }

    /**
     * 正常系: 保留中の state と一致するコールバックはトークン交換されログインが確立する
     */
    public function test_first_login_succeeds(): void
    {
        $this->mockSso($this->defaultClaims());
        $this->provider->shouldReceive('getAccessToken')
            ->once()
            ->with('authorization_code', ['code' => 'code-1'])
            ->andReturn($this->token());

        $response = $this->withSession([
            'sso.state' => 'state-1',
            'sso.pkce' => 'pkce-1',
            'sso.nonce' => 'nonce-1',
        ])->get('/auth/callback?code=code-1&state=state-1');

        $response->assertRedirect();
        $response->assertCookie('session_id');
        $this->assertStringNotContainsString('sso_error', $response->headers->get('Location'));
        $this->assertDatabaseHas('users', ['sso_sub' => 'idp-sub-0001']);
    }

    /**
     * 本題の再現ケース: 1回目成功 → 2回目のログイン（新しい code）も成功する．
     * 交換は各ログインで新しいコードに対して1回ずつのみ行われる．
     */
    public function test_second_login_succeeds_with_new_code(): void
    {
        $this->mockSso($this->defaultClaims());
        $this->provider->shouldReceive('getAccessToken')
            ->once()->with('authorization_code', ['code' => 'code-1'])
            ->andReturn($this->token());
        $this->provider->shouldReceive('getAccessToken')
            ->once()->with('authorization_code', ['code' => 'code-2'])
            ->andReturn($this->token());

        // 1回目
        $this->withSession([
            'sso.state' => 'state-1',
            'sso.pkce' => 'pkce-1',
            'sso.nonce' => 'nonce-1',
        ])->get('/auth/callback?code=code-1&state=state-1')->assertRedirect();

        // ログアウト相当（セッションクッキーなし）後の2回目: 新しい state/code
        $response = $this->withSession([
            'sso.state' => 'state-2',
            'sso.pkce' => 'pkce-2',
            'sso.nonce' => 'nonce-2',
        ])->get('/auth/callback?code=code-2&state=state-2');

        $response->assertRedirect();
        $this->assertStringNotContainsString('sso_error', $response->headers->get('Location'));

        // 再ログインでユーザーが重複作成されないこと
        $this->assertSame(1, User::where('sso_sub', 'idp-sub-0001')->count());
    }

    /**
     * 消費済みの古い callback URL への再アクセス（未ログイン）:
     * トークン交換を行わず，500 にもせず，再ログイン誘導へ戻す
     */
    public function test_replayed_callback_without_pending_state_redirects_to_login(): void
    {
        $this->mockSso($this->defaultClaims());
        $this->provider->shouldReceive('getAccessToken')->never();

        $response = $this->get('/auth/callback?code=old-code&state=old-state');

        $response->assertRedirect();
        $this->assertStringContainsString('/login?sso_error=', $response->headers->get('Location'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /**
     * コールバック再訪（ブラウザ「戻る」等）: ログイン済みなら交換を再実行せず
     * そのままアプリへ戻す（1回目成功→古いURL再処理→invalid_grant，の経路を塞ぐ）
     */
    public function test_replayed_callback_while_logged_in_redirects_to_app(): void
    {
        $this->mockSso($this->defaultClaims());
        $this->provider->shouldReceive('getAccessToken')->never();

        $user = User::create([
            'user_id' => 'tomoki',
            'sso_sub' => 'idp-sub-0001',
            'username' => 'Tomoki',
            'password' => 'dummy-password',
            'is_admin' => false,
            'is_active' => true,
        ]);
        $session = Session::createForUser($user);

        $response = $this->withCookie('session_id', $session->id)
            ->get('/auth/callback?code=consumed-code&state=old-state');

        $response->assertRedirect();
        $this->assertStringContainsString('/papers', $response->headers->get('Location'));
        $this->assertStringNotContainsString('sso_error', $response->headers->get('Location'));
    }

    /**
     * state 不一致（別タブでのフロー開始等）: 交換せず再ログイン誘導，
     * かつ検証値は pull 済み（一度きり使用）である
     */
    public function test_state_mismatch_does_not_exchange_and_consumes_state(): void
    {
        $this->mockSso($this->defaultClaims());
        $this->provider->shouldReceive('getAccessToken')->never();

        $response = $this->withSession([
            'sso.state' => 'expected-state',
            'sso.pkce' => 'pkce-1',
            'sso.nonce' => 'nonce-1',
        ])->get('/auth/callback?code=code-1&state=wrong-state');

        $response->assertRedirect();
        $this->assertStringContainsString('/login?sso_error=', $response->headers->get('Location'));
        $response->assertSessionMissing('sso.state');
        $response->assertSessionMissing('sso.pkce');
        $response->assertSessionMissing('sso.nonce');
    }

    /**
     * 同一の callback URL を2度処理しても（1回目の直後にリロード等），
     * 2度目は交換されない（getAccessToken は合計1回のみ）
     */
    public function test_same_callback_url_is_not_exchanged_twice(): void
    {
        $this->mockSso($this->defaultClaims());
        $this->provider->shouldReceive('getAccessToken')
            ->once()
            ->andReturn($this->token());

        $first = $this->withSession([
            'sso.state' => 'state-1',
            'sso.pkce' => 'pkce-1',
            'sso.nonce' => 'nonce-1',
        ])->get('/auth/callback?code=code-1&state=state-1');
        $first->assertRedirect();

        // 2度目（state は pull 済み・セッションに残っていない）
        $second = $this->get('/auth/callback?code=code-1&state=state-1');
        $second->assertRedirect();
        $this->assertStringContainsString('/login?sso_error=', $second->headers->get('Location'));
    }

    /**
     * IdP がトークン交換を拒否（invalid_grant 等）した場合，500 にせず再ログイン誘導へ
     */
    public function test_token_exchange_failure_redirects_to_login_not_500(): void
    {
        $this->mockSso($this->defaultClaims());
        $this->provider->shouldReceive('getAccessToken')
            ->once()
            ->andThrow(new \League\OAuth2\Client\Provider\Exception\IdentityProviderException(
                'invalid_grant',
                400,
                ['error' => 'invalid_grant', 'hint' => 'Authorization code has been revoked']
            ));

        $response = $this->withSession([
            'sso.state' => 'state-1',
            'sso.pkce' => 'pkce-1',
            'sso.nonce' => 'nonce-1',
        ])->get('/auth/callback?code=revoked-code&state=state-1');

        $response->assertRedirect();
        $this->assertStringContainsString('/login?sso_error=', $response->headers->get('Location'));
    }
}
