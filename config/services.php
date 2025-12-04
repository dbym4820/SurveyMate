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
    | AI Services
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'provider' => env('AI_PROVIDER', 'claude'),
        'claude_api_key' => env('CLAUDE_API_KEY'),
        'claude_model' => env('CLAUDE_MODEL', 'claude-sonnet-4-20250514'),
        'openai_api_key' => env('OPENAI_API_KEY'),
        'openai_model' => env('OPENAI_MODEL', 'gpt-4o'),
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
