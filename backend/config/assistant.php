<?php

return [
    'provider' => env('ASSISTANT_PROVIDER', 'deterministic'),
    // Deliberately loopback-only. No cloud URL, credentials or company data egress.
    'local_url' => env('ASSISTANT_LOCAL_URL', 'http://127.0.0.1:11434'),
    'local_model' => env('ASSISTANT_LOCAL_MODEL', ''),
    'timeout_seconds' => 3,
    'max_tools' => 6,
    'runtime_seconds' => 12,
    'context_minutes' => 60,
];
