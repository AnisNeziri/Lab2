<?php

return [
    // This controls the data/authentication model. Desktop AIMS is local-first
    // (`offline`) even when an explicitly configured tracking integration is
    // allowed to use the internet; see tracking.external_enabled.
    'operation_mode' => env('APP_OPERATION_MODE', 'online'),
];
