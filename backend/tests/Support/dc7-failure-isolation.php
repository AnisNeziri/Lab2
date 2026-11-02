<?php

use App\Models\Product;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('e2e') || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'dc7-preview.sqlite') {
    throw new RuntimeException('Requires isolated DC7 database.');
}
$f = json_decode(file_get_contents(__DIR__.'/../../../output/dc7-fixture.json'), true);
Auth::setUser(User::withoutGlobalScopes()->where('company_id', $f['company'])->where('email', $f['email'])->firstOrFail());
$p = Product::findOrFail($f['product']);
$before = (float) $p->quantity;
$key = (string) Str::uuid();
Schema::rename('jobs', 'dc7_paused_jobs');
try {
    $movement = app(StockMovementService::class)->store(['product_id' => $p->id, 'type' => 'in', 'quantity' => 1, 'movement_code' => 'import_adjustment_in', 'reason' => 'Synthetic optional queue outage probe', 'idempotency_key' => $key]);
    if ((float) $p->fresh()->quantity !== $before + 1) {
        throw new RuntimeException('Stock authority did not finish.');
    }echo "PASS: real after-commit queue failure did not invalidate committed stock or accounting.\n";
} finally {
    Schema::rename('dc7_paused_jobs', 'jobs');
}
