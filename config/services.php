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

    'google_sign_in' => [
        // Web OAuth client ID (audience), from the Google/Firebase project.
        // Never accept a client-supplied audience or a Google access token.
        'web_client_id' => env('GOOGLE_WEB_CLIENT_ID', ''),
    ],

    'm20_fcm' => [
        // Stage 2 is opt-in and fails closed until a PRIVATE credentials file
        // is configured on the server. Never commit service-account JSON.
        'enabled' => (bool) env('M20_FCM_ENABLED', false),
        'project_id' => env('M20_FCM_PROJECT_ID', 'sign-kamran'),
        'credentials_path' => env('M20_FCM_CREDENTIALS_PATH', ''),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'quote_model' => env('OPENAI_QUOTE_MODEL', 'gpt-4o-mini'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
