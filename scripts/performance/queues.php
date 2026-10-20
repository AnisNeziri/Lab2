<?php

use App\Models\AnalyticsSnapshot;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\StrategicSimulation;
use App\Models\SupplyOptimizationPlan;
use App\Services\BusinessEventService;
use App\Services\StrategicSimulationService;
use App\Services\SupplyOptimizerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/bootstrap.php';

$mode = $argv[2] ?? 'status';
if ($mode === 'setup') {
    $ids = [];
    $stamp = Str::uuid();
    for ($i = 0; $i < 5; $i++) {
        $p = Product::create(['name' => 'PERFORMANCE queue '.$stamp.' '.$i, 'sku' => 'Q-'.$stamp.'-'.$i, 'unit' => 'pcs', 'quantity' => 50, 'min_quantity' => 20, 'high_stock_threshold' => 5000, 'purchase_price' => 2, 'price' => 5, 'category_id' => DB::table('categories')->where('company_id', $owner->company_id)->value('id')]);
        $p->forceFill(['created_at' => now()->subYear()])->save();
        $ids[] = $p->id;
        ProductSupplier::create(['product_id' => $p->id, 'supplier_id' => DB::table('suppliers')->where('company_id', $owner->company_id)->value('id'), 'purchase_price' => 2, 'currency' => 'EUR', 'exchange_rate_to_base' => 1, 'usual_lead_time_days' => 3, 'pack_size' => 5, 'minimum_order_quantity' => 10, 'is_active' => true]);
        for ($d = 1; $d <= 90; $d++) {
            AnalyticsSnapshot::create(['entity_type' => 'demand_observation', 'entity_id' => $p->id, 'warehouse_id' => 0, 'snapshot_date' => today()->subDays($d), 'observed_at' => today()->subDays($d)->endOfDay(), 'feature_version' => 'observed-v2', 'facts' => ['unit' => 'pcs', 'demand' => 10, 'sales_quantity' => 10, 'returns_quantity' => 0, 'available' => 100, 'stockout' => false, 'quality' => 'observed', 'complete' => true, 'potentially_censored' => false, 'qualification' => 'PERFORMANCE synthetic facts; not forecast certification']]);
        }
    }
    for ($i = 0; $i < 30; $i++) {
        app(BusinessEventService::class)->record('pr2.performance.probe', $p, 'PERFORMANCE no matching automations', [], 'pr2-queue-'.$stamp.'-'.$i);
    }
    $scope = ['horizon' => 30, 'product_ids' => $ids, 'commitment_limit' => 8000, 'allow_transfers' => false];
    $opt = app(SupplyOptimizerService::class)->submit($scope);
    $sim = app(StrategicSimulationService::class)->submit(['name' => 'PERFORMANCE resource probe '.$stamp, 'horizon' => 30, 'optimize' => true, 'scope' => ['product_ids' => $ids, 'allow_transfers' => false], 'assumptions' => [['type' => 'demand', 'action' => 'percent', 'value' => 20]]]);
    $cancel = app(SupplyOptimizerService::class)->submit(array_replace($scope, ['horizon' => 60]));
    app(SupplyOptimizerService::class)->cancel($cancel['id']);
    $cancelSim = app(StrategicSimulationService::class)->submit(['name' => 'PERFORMANCE cancelled '.$stamp, 'horizon' => 60, 'optimize' => true, 'scope' => ['product_ids' => $ids], 'assumptions' => []]);
    app(StrategicSimulationService::class)->cancel($cancelSim['id']);
    echo json_encode(['environment' => $environment, 'optimizer_id' => $opt['id'], 'simulation_id' => $sim['id'], 'cancelled_optimizer_id' => $cancel['id'], 'cancelled_simulation_id' => $cancelSim['id'], 'queue_depth' => DB::table('jobs')->select('queue')->selectRaw('COUNT(*) as count')->groupBy('queue')->get()]);
} else {
    echo json_encode(['queues' => DB::table('jobs')->select('queue')->selectRaw('COUNT(*) as count')->groupBy('queue')->get(), 'failed_jobs' => DB::table('failed_jobs')->count(), 'plans' => SupplyOptimizationPlan::orderBy('id')->get(['id', 'status', 'error'])->toArray(), 'simulations' => StrategicSimulation::orderBy('id')->get(['id', 'status', 'stage', 'error'])->toArray(), 'operational_counts' => collect(['purchase_orders', 'stock_movements', 'journal_entries', 'financial_account_transactions', 'purchase_requests', 'stock_transfers'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all()]);
}
