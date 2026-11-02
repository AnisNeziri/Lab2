<?php
return [
    // Exact frontend origins, independent of the API request Host header.
    'passkeys' => [
        'rp_id' => env('AIMS_PASSKEY_RP_ID', parse_url(env('FRONTEND_URL', 'http://localhost:5173'), PHP_URL_HOST)),
        'origins' => array_values(array_filter(array_map('trim', explode(',', env('AIMS_PASSKEY_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173')))))),
    ],
    'recent_auth_seconds' => 600,
    'license_public_key' => env('AIMS_LICENSE_PUBLIC_KEY_FILE'),
    'update_public_key' => env('AIMS_UPDATE_PUBLIC_KEY_FILE'),
    'minimum_free_bytes' => 536870912,
    'recovery_key_hash' => env('AIMS_IDENTITY_RECOVERY_KEY_HASH'),
    'desktop_license_file' => env('AIMS_DESKTOP_LICENSE_FILE'),
    'desktop_license_key' => env('AIMS_DESKTOP_LICENSE_PUBLIC_KEY'),
    'desktop_machine_id' => env('AIMS_DESKTOP_LICENSE_MACHINE_ID'),
];
