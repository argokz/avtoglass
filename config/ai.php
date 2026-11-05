<?php

return [
    'mode' => env('AI_PROVIDER_MODE', 'gemini_only'),
    // Use a Gemini project without billing. A model name cannot enforce account billing policy.
    'keys' => array_values(array_filter([env('GEMINI_API_KEY'), env('GEMINI_API_KEY_SECOND')])),
    'models' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_MODELS', 'gemini-3.5-flash-lite,gemini-3.6-flash,gemini-3.1-flash-lite'))))),
    // These IDs answered on the free project key. gemini-3.1-flash-lite stays last: it timed out.
    'approved_gemini_models' => ['gemini-3.5-flash-lite', 'gemini-3.6-flash', 'gemini-3.8-flash', 'gemini-3.1-flash-lite'],
    'cooldown_minutes' => (int) env('GEMINI_COOLDOWN_MINUTES', 60),
    'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
    'litellm' => [
        'endpoint' => env('LITELLM_BASE_URL', ''),
        'key' => env('LITELLM_API_KEY', ''),
        // Only this audited local alias is accepted; proxy key must also deny paid routes.
        'model' => env('LITELLM_MODEL', 'qwen-chat'),
        'timeout' => min(20, max(1, (float) env('LITELLM_REQUEST_TIMEOUT', 15))),
    ],
    'sync_budget_seconds' => min(20, max(1, (float) env('AI_SYNC_BUDGET_SECONDS', 20))),
    'job_budget_seconds' => min(30, max(1, (float) env('AI_JOB_BUDGET_SECONDS', 30))),
    'request_timeout' => min(15, max(1, (float) env('AI_REQUEST_TIMEOUT', 8))),
    'max_attempts' => min(2, max(1, (int) env('AI_MAX_ATTEMPTS', 2))),
    'max_concurrent' => max(1, (int) env('AI_MAX_CONCURRENT', 10)),
    'provider_requests_per_minute' => max(1, (int) env('AI_PROVIDER_RPM', 10)),
    'retention' => [
        'guest_days' => max(1, (int) env('AI_GUEST_HISTORY_DAYS', 30)),
        'account_days' => max(1, (int) env('AI_ACCOUNT_HISTORY_DAYS', 180)),
        'import_days' => max(1, (int) env('AI_IMPORT_FILE_DAYS', 7)),
    ],
    'max_tool_steps' => 3,
    'max_output_tokens' => 512,
    'context_characters' => 16000,
    'rate_limit_per_minute' => 20,
    'queue_connection' => env('AI_QUEUE_CONNECTION', 'database'),
    'queue_name' => env('AI_QUEUE_NAME', 'ai'),
    'queue_wait_seconds' => 120,
    'turn_timeout_seconds' => 45,
];
