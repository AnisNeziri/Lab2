<?php

use App\Jobs\RunBusinessAutomations;
use App\Models\AutomationExecution;
use App\Models\BusinessEvent;
use App\Models\Company;
use App\Models\OperationalTask;
use App\Models\Product;
use App\Models\User;
use App\Services\AutomationService;
use App\Services\AutomationTaskWorkflow;
use App\Services\BusinessEventService;
use App\Services\StockMovementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! str_ends_with(config('database.connections.mysql.database'), '_test') || (int) config('database.connections.mysql.port') !== 3315) {
    throw new RuntimeException('Requires the isolated MariaDB 3315 test instance.');
}
if (in_array($argv[1] ?? '', ['event', 'claim'], true)) {
    Auth::login(User::findOrFail((int) $argv[2]));
    while (microtime(true) < (float) $argv[4]) {
        usleep(1000);
    }
    if ($argv[1] === 'event') {
        app(AutomationService::class)->consume(BusinessEvent::findOrFail((int) $argv[3]));
        echo 'processed';
    } else {
        try {
            app(AutomationTaskWorkflow::class)->claim(OperationalTask::findOrFail((int) $argv[3]));
            echo 'claimed';
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }echo 'conflict';
        }
    }
    exit;
}
(new RolePermissionSeeder)->run();
Queue::fake([RunBusinessAutomations::class]);
$company = Company::factory()->create();
$owner = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
Auth::login($owner);
$product = Product::create(['name' => 'DC6 concurrent stock', 'sku' => 'DC6-RACE', 'unit' => 'pcs', 'quantity' => 0, 'min_quantity' => 20, 'price' => 5, 'purchase_price' => 2]);
app(StockMovementService::class)->store(['product_id' => $product->id, 'type' => 'in', 'quantity' => 10, 'movement_code' => 'import_adjustment_in', 'reason' => 'Synthetic DC6 concurrency', 'idempotency_key' => 'dc6-race-opening']);
$rule = app(AutomationService::class)->save(['name' => 'Race review', 'trigger' => 'inventory.low_stock', 'priority' => 'high', 'conditions' => ['all' => []], 'actions' => [['type' => 'create_task', 'title' => 'Concurrent review', 'assigned_team' => 'purchasing']]]);
app(AutomationService::class)->toggle($rule, true);
$event = app(BusinessEventService::class)->record('inventory.low_stock', $product, $product->name, ['available' => 10, 'minimum' => 20, 'shortage' => 10], 'dc6-race-event');
$run = function (string $mode, array $users, int $id): array {
    $barrier = microtime(true) + .4;
    $processes = [];
    foreach ($users as $user) {
        $p = new Process([PHP_BINARY, __FILE__, $mode, (string) $user, (string) $id, (string) $barrier], base_path(), null, null, 30);
        $p->start();
        $processes[] = $p;
    }
    $outputs = [];
    foreach ($processes as $p) {
        $p->wait();
        if (! $p->isSuccessful()) {
            throw new RuntimeException($p->getErrorOutput().$p->getOutput());
        }$outputs[] = trim($p->getOutput());
    }

return $outputs;
};
$run('event', [$owner->id, $owner->id], $event->id);
if (OperationalTask::where('automation_id', $rule->id)->count() !== 1 || AutomationExecution::where('automation_id', $rule->id)->count() !== 1) {
    throw new RuntimeException('Concurrent workers duplicated the effect.');
}
$task = OperationalTask::where('automation_id', $rule->id)->firstOrFail();
$a = User::factory()->create(['company_id' => $company->id, 'role' => 'manager']);
$b = User::factory()->create(['company_id' => $company->id, 'role' => 'manager']);
$results = $run('claim', [$a->id, $b->id], $task->id);
sort($results);
if ($results !== ['claimed', 'conflict']) {
    throw new RuntimeException('Task ownership was not exclusive: '.json_encode($results));
}
echo "PASS: two workers deliver one event → one execution and one task; two claimants → one owner and one conflict.\n";
