<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HCIMLab SSO (OpenID Connect)
    |--------------------------------------------------------------------------
    |
    | 研究室認証基盤（hcimlab_auth）との OIDC 連携設定．
    | SSO_IDP_URL はサブパス（/hcimlab_auth 等）を含むIdPのベースURL．
    | エンドポイントの正準値は discovery から取得する．
    |
    */
    'sso' => [
        'enabled' => env('SSO_ENABLED', false),
        'idp_url' => rtrim((string) env('SSO_IDP_URL', ''), '/'),
        'client_id' => env('SSO_CLIENT_ID'),
        'client_secret' => env('SSO_CLIENT_SECRET'),
        // 未指定時は APP_URL + /auth/callback（IdP側の登録値と完全一致させること）
        'redirect_uri' => env('SSO_REDIRECT_URI'),
        'scopes' => env('SSO_SCOPES', 'openid profile email lab'),
        'button_label' => env('SSO_BUTTON_LABEL', 'HCIMLab SSOでログイン'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Services
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'provider' => env('AI_PROVIDER', 'openai'),

        // OpenAI
        'openai_api_key' => env('OPENAI_API_KEY'),
        'openai_default_model' => env('OPENAI_DEFAULT_MODEL', 'gpt-4o'),
        'openai_available_models' => env('OPENAI_AVAILABLE_MODELS', 'gpt-4o,gpt-4o-mini,o3-mini,o1-mini,gpt-4-turbo,gpt-3.5-turbo'),

        // Claude (Anthropic)
        'claude_api_key' => env('CLAUDE_API_KEY'),
        'claude_default_model' => env('CLAUDE_DEFAULT_MODEL', 'claude-sonnet-4-20250514'),
        'claude_available_models' => env('CLAUDE_AVAILABLE_MODELS', 'claude-sonnet-4-20250514,claude-opus-4-20250514,claude-3-5-haiku-20241022'),

        // Admin API keys (fallback when user has no API key)
        'admin_claude_api_key' => env('ADMIN_CLAUDE_API_KEY'),
        'admin_openai_api_key' => env('ADMIN_OPENAI_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | RSS Fetching
    |--------------------------------------------------------------------------
    */
    'fetch' => [
        'enabled' => env('FETCH_ENABLED', true),
        'schedule' => env('FETCH_SCHEDULE', '0 6 * * *'),
        'min_interval' => env('FETCH_MIN_INTERVAL', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Push Notifications
    |--------------------------------------------------------------------------
    |
    | VAPID keys for Web Push notifications.
    | Generate keys at: https://web-push-codelab.glitch.me/
    |
    */
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@example.com'),
    ],

];
