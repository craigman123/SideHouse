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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    'gcash_sms' => [
        'secret' => env('GCASH_SMS_WEBHOOK_SECRET'),
        'allowed_ips' => env('GCASH_SMS_ALLOWED_IPS'),
    ],

    'landbank_sms' => [
        'secret' => env('LANDBANK_SMS_WEBHOOK_SECRET'),
        'allowed_ips' => env('LANDBANK_SMS_ALLOWED_IPS'),
    ],
    
    'sms_webhook' => [
        'log_raw' => env('SMS_WEBHOOK_LOG_RAW', false),
    ],

    'cron_secret' => [
        'secret' => env('CRON_SECRET'),
    ],

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
    ],

];