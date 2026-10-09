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
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5'),
        // Une pause plus longue que cette durée entre deux messages ouvre une nouvelle session :
        // l'historique envoyé à Claude s'arrête là.
        'session_gap_hours' => (float) env('AI_SESSION_GAP_HOURS', 6),
        // Statistiques de coût de l'admin : prix publics en dollars par million de tokens
        // (platform.claude.com/docs/en/about-claude/pricing, relevés le 09/10/2026), par
        // modèle. Une réponse enregistrée avec un modèle absent de cette table est
        // affichée « prix inconnu ». cache_write : écriture en cache 5 minutes, et
        // cache_read : lecture du cache, en multiples du prix d'entrée.
        'pricing' => [
            'usd_to_xof' => (float) env('AI_COST_USD_TO_XOF', 600),
            'models' => [
                'claude-haiku-4-5' => ['input' => 1.0, 'output' => 5.0, 'cache_write' => 1.25, 'cache_read' => 0.1],
                // Prix des requêtes de moins de 100 000 tokens (au-delà, ×5 : jamais le cas ici).
                'claude-haiku-5-5' => ['input' => 0.1, 'output' => 0.5, 'cache_write' => 1.25, 'cache_read' => 0.1],
                // Retiré de l'API Claude, gardé pour l'historique.
                'claude-sonnet-4' => ['input' => 3.0, 'output' => 15.0, 'cache_write' => 1.25, 'cache_read' => 0.1],
                'claude-sonnet-4-5' => ['input' => 3.0, 'output' => 15.0, 'cache_write' => 1.25, 'cache_read' => 0.1],
                'claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0, 'cache_write' => 1.25, 'cache_read' => 0.1],
                'claude-sonnet-5' => ['input' => 2.0, 'output' => 10.0, 'cache_write' => 1.25, 'cache_read' => 0.1],
                'claude-sonnet-5-5' => ['input' => 2.0, 'output' => 10.0, 'cache_write' => 1.25, 'cache_read' => 0.05],
            ],
        ],
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

    // Paiement manuel des abonnements (en XOF) : un moyen sans numéro n'est pas proposé.
    'payments' => [
        'account_name' => env('PAYMENT_ACCOUNT_NAME', 'Causeo'),
        'orange_money_number' => env('PAYMENT_ORANGE_MONEY_NUMBER'),
        'moov_money_number' => env('PAYMENT_MOOV_MONEY_NUMBER'),
    ],

];
