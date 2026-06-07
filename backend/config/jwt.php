<?php

return [
    'secret' => env('JWT_SECRET', env('APP_KEY')),
    'access_ttl' => (int) env('JWT_ACCESS_TTL', 900),
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 2_592_000),
];
