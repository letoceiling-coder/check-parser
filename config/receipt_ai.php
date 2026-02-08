<?php

return [
    'enabled' => env('RECEIPT_AI_ENABLED', false),
    'api_url' => env('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions'),
    'api_key' => env('OPENAI_API_KEY', ''),
    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'max_tokens' => (int) env('RECEIPT_AI_MAX_TOKENS', 256),
    'timeout' => (int) env('RECEIPT_AI_TIMEOUT', 15),

    'validation' => [
        'amount_min' => 0,
        'amount_max' => 10_000_000,
        'confidence_threshold' => 0.85,
        'date_max_years_ago' => 2,
    ],
];
