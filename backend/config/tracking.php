<?php

return [
    // Desktop AIMS keeps its company database local (APP_OPERATION_MODE=offline)
    // but may still use explicitly configured internet integrations. Keeping
    // this switch separate prevents local-first authentication rules from
    // accidentally disabling a configured AIS worker.
    'external_enabled' => filter_var(
        env('TRACKING_EXTERNAL_ENABLED', env('APP_OPERATION_MODE', 'online') === 'online'),
        FILTER_VALIDATE_BOOL,
    ),
    'vessel_provider' => env('TRACKING_VESSEL_PROVIDER', 'aisstream'),
    'aisstream' => ['api_key' => env('AISSTREAM_API_KEY'), 'url' => env('AISSTREAM_URL', 'wss://stream.aisstream.io/v0/stream')],
    'vessel_lookup' => [
        'provider' => env('TRACKING_VESSEL_LOOKUP_PROVIDER', 'vesselapi'),
        'api_key' => env('VESSELAPI_API_KEY'),
        'base_url' => rtrim(env('VESSELAPI_BASE_URL', 'https://api.vesselapi.com'), '/'),
        'cache_minutes' => (int) env('VESSELAPI_CACHE_MINUTES', 10),
    ],
    'shipment_provider' => env('TRACKING_SHIPMENT_PROVIDER', 'disabled'),
    'air_cargo_provider' => env('TRACKING_AIR_CARGO_PROVIDER', 'disabled'),

    'vessel_cache_ttl' => (int) env('TRACKING_VESSEL_CACHE_TTL', 20),
    'shipment_refresh_minutes' => (int) env('TRACKING_SHIPMENT_REFRESH_MINUTES', 10),
    'alert_distance_km' => (int) env('TRACKING_ALERT_DISTANCE_KM', 50),
    'live_position_minutes' => (int) env('TRACKING_LIVE_POSITION_MINUTES', 10),
    'outside_coverage_hours' => (int) env('TRACKING_OUTSIDE_COVERAGE_HOURS', 24),
    'subscription_rotation_seconds' => (int) env('TRACKING_SUBSCRIPTION_ROTATION_SECONDS', 300),

    'retry' => [
        'times' => 3,
        'backoff_seconds' => [30, 120, 300],
    ],

    'statuses' => [
        'registered',
        'processing',
        'departed',
        'in_transit',
        'arrived_at_port',
        'customs_clearance',
        'out_for_delivery',
        'delivered',
        'delayed',
    ],

];
