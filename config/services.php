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

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
        'scopes' => array_filter(array_map('trim', explode(',', env('FACEBOOK_SCOPES', 'pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement,pages_read_user_content')))),
        'login_config_id' => env('FACEBOOK_LOGIN_CONFIG_ID'),
    ],

    'instagram' => [
        'client_id' => env('INSTAGRAM_CLIENT_ID', env('FACEBOOK_CLIENT_ID')),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET', env('FACEBOOK_CLIENT_SECRET')),
        'redirect' => env('INSTAGRAM_REDIRECT_URI'),
        'scopes' => array_filter(array_map('trim', explode(',', env('INSTAGRAM_SCOPES', 'instagram_business_basic,instagram_business_content_publish,instagram_business_manage_insights,instagram_business_manage_comments')))),
    ],

    'google_business' => [
        'client_id' => env('GOOGLE_BUSINESS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_BUSINESS_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_BUSINESS_REDIRECT_URI'),
        'scopes' => array_filter(array_map('trim', explode(',', env('GOOGLE_BUSINESS_SCOPES', 'https://www.googleapis.com/auth/business.manage')))),
    ],

];
