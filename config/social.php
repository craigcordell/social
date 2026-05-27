<?php

return [
    'providers' => [
        'facebook' => [
            'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v25.0'),
            'rate_limit_per_minute' => (int) env('FACEBOOK_RATE_LIMIT_PER_MINUTE', 20),
        ],

        'instagram' => [
            'rate_limit_per_minute' => (int) env('INSTAGRAM_RATE_LIMIT_PER_MINUTE', 10),
        ],

        'google_business_profile' => [
            'rate_limit_per_minute' => (int) env('GOOGLE_BUSINESS_PROFILE_RATE_LIMIT_PER_MINUTE', 10),
        ],
    ],
];
