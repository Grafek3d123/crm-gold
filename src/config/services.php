<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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
    | Отдельное рекламное приложение (Яндекс Директ / Метрика).
    | Доставка выключена, пока URL, схема payload и способ авторизации
    | не согласованы с владельцем рекламного приложения.
    */
    'advertising_app' => [
        'webhook_url' => env('ADVERTISING_APP_WEBHOOK_URL'),
        'signing_secret' => env('ADVERTISING_APP_SIGNING_SECRET'),
        'timeout' => env('ADVERTISING_APP_TIMEOUT', 10),
    ],

];
