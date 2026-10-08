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

    // Chemin vers un bundle CA personnalisé pour les requêtes HTTPS sortantes
    // (utile en dev Windows/WAMP). Laisser vide en production Linux : true = CA système.
    'curl_ca_bundle' => env('CURL_CA_BUNDLE', true),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        // Une pause plus longue que cette durée entre deux messages ouvre une nouvelle session :
        // l'historique envoyé à Claude s'arrête là.
        'session_gap_hours' => (float) env('AI_SESSION_GAP_HOURS', 6),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
    ],

    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        // Secret de l'app Meta qui reçoit les webhooks (vérification de X-Hub-Signature-256).
        'app_secret' => env('WHATSAPP_APP_SECRET', env('FACEBOOK_APP_SECRET')),
        'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v21.0/'),
    ],

    'whatsapp_bridge' => [
        'url' => env('WHATSAPP_BRIDGE_URL', 'http://localhost:3001'),
        'token' => env('WHATSAPP_BRIDGE_TOKEN', 'secret_bridge_token_2024'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
    ],

];
