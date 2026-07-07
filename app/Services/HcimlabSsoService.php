<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use League\OAuth2\Client\Provider\GenericProvider;
use RuntimeException;

/**
 * HCIMLab SSO（hcimlab_auth）との OpenID Connect 連携．
 *
 * Authorization Code + PKCE フローを league/oauth2-client で行い，
 * id_token は firebase/php-jwt + JWKS で署名検証する．
 * エンドポイントの正準値は discovery から取得する（OIDC仕様準拠）．
 */
class HcimlabSsoService
{
    private const DISCOVERY_CACHE_TTL = 3600;
    private const JWKS_CACHE_TTL = 3600;

    public function isEnabled(): bool
    {
        return (bool) config('services.sso.enabled')
            && !empty(config('services.sso.idp_url'))
            && !empty(config('services.sso.client_id'));
    }

    public function buttonLabel(): string
    {
        return config('services.sso.button_label', 'SSOでログイン');
    }

    public function redirectUri(): string
    {
        return config('services.sso.redirect_uri')
            ?: rtrim(config('app.url'), '/') . '/auth/callback';
    }

    /**
     * OIDC discovery ドキュメントを取得（キャッシュあり）
     */
    public function discovery(): array
    {
        $idpUrl = config('services.sso.idp_url');

        return Cache::remember('sso:discovery:' . md5($idpUrl), self::DISCOVERY_CACHE_TTL, function () use ($idpUrl) {
            $response = Http::timeout(10)->acceptJson()->get($idpUrl . '/.well-known/openid-configuration');

            if (!$response->successful()) {
                throw new RuntimeException('SSO discovery の取得に失敗しました: HTTP ' . $response->status());
            }

            $doc = $response->json();
            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
                if (empty($doc[$key])) {
                    throw new RuntimeException("SSO discovery に {$key} が含まれていません");
                }
            }

            return $doc;
        });
    }

    /**
     * PKCE (S256) 有効な OAuth2 プロバイダを構築
     */
    public function provider(): GenericProvider
    {
        $discovery = $this->discovery();

        return new GenericProvider([
            'clientId' => config('services.sso.client_id'),
            'clientSecret' => config('services.sso.client_secret'),
            'redirectUri' => $this->redirectUri(),
            'urlAuthorize' => $discovery['authorization_endpoint'],
            'urlAccessToken' => $discovery['token_endpoint'],
            'urlResourceOwnerDetails' => $discovery['userinfo_endpoint'] ?? (config('services.sso.idp_url') . '/oauth/userinfo'),
            'scopes' => config('services.sso.scopes', 'openid profile email lab'),
            'scopeSeparator' => ' ',
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
        ]);
    }

    /**
     * id_token を JWKS で署名検証し，iss / aud / exp / nonce を検証してクレームを返す
     */
    public function verifyIdToken(string $idToken, string $expectedNonce): array
    {
        $discovery = $this->discovery();
        $keys = JWK::parseKeySet($this->jwks($discovery['jwks_uri']), 'RS256');

        // JWT::decode が署名・exp・nbf を検証する（自前でJWTを解析しない）
        $claims = (array) JWT::decode($idToken, $keys);

        if (($claims['iss'] ?? null) !== $discovery['issuer']) {
            throw new RuntimeException('id_token の iss が一致しません');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (!in_array(config('services.sso.client_id'), $audiences, true)) {
            throw new RuntimeException('id_token の aud が一致しません');
        }

        if (empty($expectedNonce) || ($claims['nonce'] ?? null) !== $expectedNonce) {
            throw new RuntimeException('id_token の nonce が一致しません');
        }

        if (empty($claims['sub'])) {
            throw new RuntimeException('id_token に sub が含まれていません');
        }

        return $claims;
    }

    /**
     * JWKS（公開鍵セット）を取得（キャッシュあり）
     */
    private function jwks(string $jwksUri): array
    {
        return Cache::remember('sso:jwks:' . md5($jwksUri), self::JWKS_CACHE_TTL, function () use ($jwksUri) {
            $response = Http::timeout(10)->acceptJson()->get($jwksUri);

            if (!$response->successful()) {
                throw new RuntimeException('SSO JWKS の取得に失敗しました: HTTP ' . $response->status());
            }

            $jwks = $response->json();
            if (empty($jwks['keys'])) {
                throw new RuntimeException('SSO JWKS に鍵が含まれていません');
            }

            return $jwks;
        });
    }
}
