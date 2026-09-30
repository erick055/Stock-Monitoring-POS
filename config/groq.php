<?php

return [
    'api_key' => env('GROQ_API_KEY'),
    'model' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
    'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
    'timeout' => (int) env('GROQ_TIMEOUT', 45),
    'max_products' => (int) env('GROQ_DEMAND_MAX_PRODUCTS', 15),
    'cache_hours' => (int) env('GROQ_DEMAND_CACHE_HOURS', 24),
];
