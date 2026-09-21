<?php

return [
    'api_key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-5.6-luna'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'ca_bundle' => env('OPENAI_CA_BUNDLE', storage_path('certs/cacert.pem')),
    'timeout' => (int) env('OPENAI_TIMEOUT', 30),
    'max_products' => (int) env('OPENAI_MAX_COMPATIBILITY_PRODUCTS', 10),
    'max_recommendations' => (int) env('OPENAI_MAX_COMPATIBILITY_RECOMMENDATIONS', 5),
    'max_output_tokens' => (int) env('OPENAI_MAX_COMPATIBILITY_OUTPUT_TOKENS', 1200),
    'reasoning_effort' => env('OPENAI_COMPATIBILITY_REASONING_EFFORT', 'none'),
    'web_search' => env('OPENAI_COMPATIBILITY_WEB_SEARCH', true),
    'cache_hours' => (int) env('OPENAI_COMPATIBILITY_CACHE_HOURS', 24),
];
