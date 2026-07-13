<?php

return [
    'llm' => [
        'base_url' => env('QINGYU_LLM_BASE_URL', env('DASHSCOPE_API_URL')),
        'api_key' => env('QINGYU_LLM_API_KEY', env('DASHSCOPE_API_KEY')),
        'model' => env('QINGYU_LLM_MODEL', env('DASHSCOPE_API_MODEL', 'qwen-plus')),
        'timeout' => (int) env('QINGYU_LLM_TIMEOUT', 45),
        'max_tokens' => (int) env('QINGYU_LLM_MAX_TOKENS', 1200),
        'temperature' => (float) env('QINGYU_LLM_TEMPERATURE', 0.8),
        'allowed_hosts' => ['dashscope.aliyuncs.com'],
    ],
];
