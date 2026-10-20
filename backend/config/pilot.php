<?php

return [
    // Explicit operator allowlist: no other company is enrolled by a browser request.
    'company_id' => env('AIMS_PILOT_COMPANY_ID') ? (int) env('AIMS_PILOT_COMPANY_ID') : null,
    'minimum_days' => 56,
    'max_window_days' => 90,
    'metric_retention_days' => 90,
    'diagnostic_retention_days' => 30,
];
