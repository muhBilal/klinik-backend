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

    /*
    | SATUSEHAT (Kemenkes) — PRD v2 5.14. Rahasia hanya di .env. `env` = sandbox | production.
    | Base URL mengikuti dokumentasi SATUSEHAT; bisa ditimpa lewat env bila Kemenkes mengubahnya.
    */
    'satusehat' => [
        'aktif' => (bool) env('SATUSEHAT_AKTIF', false),
        'env' => env('SATUSEHAT_ENV', 'sandbox'),
        'client_id' => env('SATUSEHAT_CLIENT_ID'),
        'client_secret' => env('SATUSEHAT_CLIENT_SECRET'),
        'organization_id' => env('SATUSEHAT_ORGANIZATION_ID'),
        'auth_url' => env('SATUSEHAT_AUTH_URL', env('SATUSEHAT_ENV', 'sandbox') === 'production'
            ? 'https://api-satusehat.kemkes.go.id/oauth2/v1'
            : 'https://api-satusehat-stg.dto.kemkes.go.id/oauth2/v1'),
        'base_url' => env('SATUSEHAT_BASE_URL', env('SATUSEHAT_ENV', 'sandbox') === 'production'
            ? 'https://api-satusehat.kemkes.go.id/fhir-r4/v1'
            : 'https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1'),
        'timeout' => (int) env('SATUSEHAT_TIMEOUT', 30),
    ],

    /*
    | WhatsApp Business (PRD BK-06, CR-01). Driver `log` = pesan hanya dicatat (dev/belum ada kredensial),
    | `cloud` = WhatsApp Cloud API Meta (pesan template yang sudah disetujui).
    */
    'whatsapp' => [
        'aktif' => (bool) env('WHATSAPP_AKTIF', false),
        'driver' => env('WHATSAPP_DRIVER', 'log'),
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v20.0'),
        'bahasa' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'id'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'timeout' => (int) env('WHATSAPP_TIMEOUT', 20),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
