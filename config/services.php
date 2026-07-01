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
    'whatsapp' => [
        'verify_token'    => env('WHATSAPP_VERIFY_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token'    => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret'      => env('WHATSAPP_APP_SECRET'),
        'api_version'     => env('WHATSAPP_API_VERSION', 'v18.0'),
    ],

    'telegram' => [
        // From @BotFather. Required to talk to the Bot API.
        'bot_token'      => env('TELEGRAM_BOT_TOKEN'),

        // Shared secret echoed by Telegram in `X-Telegram-Bot-Api-Secret-Token`
        // on every webhook delivery. Set this when registering the webhook
        // via `setWebhook?url=…&secret_token=…`.
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

        // Override only if you run your own Bot API server (Telegram supports
        // self-hosted bots — the official endpoint is the default).
        'api_base_url'   => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
    ],

    'qicard' => [
        'base_url'    => env('QICARD_BASE_URL'),
        'username'    => env('QICARD_USERNAME'),
        'password'    => env('QICARD_PASSWORD'),
        'terminal_id' => env('QICARD_TERMINAL_ID'),
        'currency'    => env('QICARD_CURRENCY', 'IQD'),
        'public_url'  => env('QICARD_PUBLIC_URL'),
    ],

    'platerecognizer' => [
        // API token from https://app.platerecognizer.com (Snapshot Cloud API).
        // When empty, plate-image OCR is disabled and owners type the plate.
        'token'     => env('PLATE_RECOGNIZER_TOKEN'),

        // Recognition endpoint. Override only for a self-hosted SDK.
        'endpoint'  => env('PLATE_RECOGNIZER_ENDPOINT', 'https://api.platerecognizer.com/v1/plate-reader/'),

        // Optional region hint to bias recognition. Leave empty to let the
        // engine auto-detect. NOTE: Iraq ("iq") is NOT a supported region code
        // on Plate Recognizer — sending it makes every request fail with
        // HTTP 400 ("Region \"iq\" does not exist"), so the default is blank.
        'regions'   => env('PLATE_RECOGNIZER_REGIONS', ''),

        // Reject reads below this confidence (0..1) so a blurry guess falls
        // back to manual entry instead of saving a wrong plate.
        'min_score' => (float) env('PLATE_RECOGNIZER_MIN_SCORE', 0.5),
    ],

];
