<?php

return [
    'provider' => env('ASSISTANT_PROVIDER', 'deterministic'),
    // Deliberately loopback-only. No cloud URL, credentials or company data egress.
    'local_url' => env('ASSISTANT_LOCAL_URL', 'http://127.0.0.1:11434'),
    'local_model' => env('ASSISTANT_LOCAL_MODEL', ''),
    'local_vision_model' => env('ASSISTANT_LOCAL_VISION_MODEL', ''),
    'model_revision' => env('ASSISTANT_LOCAL_MODEL_REVISION', ''),
    'model_tools' => (bool) env('ASSISTANT_MODEL_TOOLS', false),
    'model_timeout_seconds' => 45,
    'image_encoder' => env('ASSISTANT_IMAGE_ENCODER', 'colour_texture'),
    'encoder_python' => env('ASSISTANT_ENCODER_PYTHON', ''),
    'encoder_path' => env('ASSISTANT_ENCODER_PATH', ''),
    'encoder_revision' => env('ASSISTANT_ENCODER_REVISION', ''),
    'timeout_seconds' => 3,
    'max_tools' => 6,
    'runtime_seconds' => 12,
    'context_minutes' => 60,
];
