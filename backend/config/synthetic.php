<?php

return [
    'version' => 'pm3-v1',
    'seed' => 20261006,
    'days' => 90,
    'end_date' => '2026-10-06',
    'products' => 36,
    'customers' => 24,
    'suppliers' => 4,
    'warehouses' => 3,
    'order_intensity' => 4,
    'opening_cash' => '160000.00',
    'cold_start_day' => 60,
    'training_days' => [58, 72, 86],
    'validation_reserve_share' => 0.85,
    'optimizer_budgets' => [500, 60000],
    'company' => 'AIMS Demo Wholesale — SYNTHETIC / TEST DATA',
    'email' => 'owner@aims-demo.test',
    'password' => 'AimsDemo.Test.2026!',
    'document_root' => env('AIMS_DOCUMENT_ROOT'),
    'profiles' => ['stable', 'growth', 'decline', 'intermittent', 'slow', 'volume', 'expensive', 'seasonal', 'cold_start'],
];
