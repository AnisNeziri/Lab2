<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('e2e') || config('database.default') !== 'sqlite' || realpath(config('database.connections.sqlite.database')) !== realpath(base_path('database/e2e.sqlite'))) {
    throw new RuntimeException('DC3 browser fixture requires isolated e2e.sqlite.');
}
require __DIR__.'/dc3-local-fiscal-fixture.php';
dc3LocalFiscalCompany('dc3-tax@e2e.example');
echo "Prepared local synthetic signing fixture. Transport DISABLED; no ATK-issued credential.\n";
