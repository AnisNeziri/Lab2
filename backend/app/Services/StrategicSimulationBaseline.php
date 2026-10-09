<?php

namespace App\Services;

use App\Models\CustomerSalesSnapshot;
use App\Models\FinancialIntelligencePolicy;
use App\Models\InventoryForecastModel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;

/** Reads authorities once. Only the simulation run persists the resulting copy. */
final class StrategicSimulationBaseline
{
    public function freeze(array $definition): array
    {
        return DB::transaction(function () use ($definition) {
            $scope = $definition['scope'] + ['horizon' => min(90, $definition['horizon'])];
            $data = app(SupplyOptimizationData::class)->collect($scope);
            $data['as_of'] = substr($data['cutoff'], 0, 10);
            $data['strategic_horizon'] = $definition['horizon'];
            $ids = array_values(array_unique(array_column(array_column($data['rows'], 'product'), 'id')));
            $products = Product::whereIn('id', $ids)->get()->keyBy('id');
            foreach ($data['rows'] as &$r) {
                $r['product']['category_id'] = $products[$r['product']['id']]->category_id;
                $r['input']['product'] = $r['product'];
                $r['input']['as_of'] = $data['as_of'];
                $r['input']['daily'] = app(InventoryPlanningMath::class)->calculate($r['input'], ['base_quantity' => 0])['daily'];
            }unset($r);
            $orders = PurchaseOrder::whereHas('items', fn ($q) => $q->whereIn('product_id', $ids))->get(['id', 'po_number', 'supplier_id', 'warehouse_id', 'expected_at', 'status']);
            $data['purchase_order_sources'] = $orders->keyBy('id')->map(fn ($o) => ['supplier_id' => $o->supplier_id, 'warehouse_id' => $o->warehouse_id, 'reference' => $o->po_number, 'expected_at' => $o->expected_at?->toDateString(), 'status' => $o->status])->all();
            $ships = Shipment::with('purchaseOrders')->where(fn ($q) => $q->whereIn('purchase_order_id', $orders->pluck('id'))->orWhereHas('purchaseOrders', fn ($p) => $p->whereIn('purchase_orders.id', $orders->pluck('id'))))->get();
            $data['shipments'] = $ships->map(fn ($s) => ['id' => $s->id, 'reference' => $s->tracking_number, 'origin' => $s->origin_port, 'destination' => $s->destination_port, 'supplier_id' => $s->supplier_id, 'warehouse_id' => $s->warehouse_id, 'eta' => $s->eta?->toDateString(), 'purchase_order_ids' => array_values(array_unique(array_filter(array_merge([$s->purchase_order_id], $s->purchaseOrders->pluck('id')->all()))))])->all();
            $data['warehouse_balances'] = WarehouseStock::whereIn('product_id', $ids)->get()->toArray();
            $data['customer_shares'] = [];
            $data['transfer_routes'] = [];
            foreach ($definition['scope']['warehouse_ids'] ?? [] as $source) {
                foreach ($definition['scope']['warehouse_ids'] ?? [] as $dest) {
                    if ($source !== $dest) {
                        $data['transfer_routes'][$source.':'.$dest] = app(InventoryPlanningData::class)->transferLead($source, $dest);
                    }
                }
            }
            $from = today()->subDays(90)->toDateString();
            $to = today()->subDay()->toDateString();
            $ledger = app(AnalyticsSalesLedger::class)->rows($from, $to, true)->whereIn('product_id', $ids)->where('return', false);
            abort_if($ledger->count() > 20000, 422, 'Selected customer history exceeds the simulation bound. Narrow product scope.');
            foreach ($ledger->groupBy('product_id') as $p => $lines) {
                $total = $lines->sum(fn ($l) => (float) $l['quantity']);
                foreach ($lines->whereNotNull('customer_id')->groupBy('customer_id') as $c => $customer) {
                    $q = $customer->sum(fn ($l) => (float) $l['quantity']);
                    $data['customer_shares'][] = ['customer_id' => (int) $c, 'product_id' => (int) $p, 'share' => $total > 0 ? $q / $total : 0, 'observed_quantity' => $q, 'from' => $from, 'to' => $to, 'source' => 'canonical_fulfilled_sales', 'references' => $customer->pluck('sale_key')->unique()->values()->all()];
                }
            }
            $policy = FinancialIntelligencePolicy::latest('id')->first();
            $financial = app(FinancialIntelligenceEvidence::class)->assemble($policy?->settings['champion'] ?? 'due_date_baseline');
            $data['v7'] = ['id' => null, 'as_of' => $data['as_of'], 'evidence' => $financial, 'forecast' => app(FinancialForecastMath::class)->calculate($financial, $definition['horizon'])];
            $data['customer_shares'] = array_merge($data['customer_shares'], $this->warehouseShares($ledger, $ids, $data['customer_shares'], $definition['scope']['warehouse_ids'] ?? [], $from, $to));
            $data['versions'] = ['simulation' => 'strategic-simulation-v12.1', 'planning' => 'inventory-planning-v4.1', 'optimizer' => 'supply-milp-v11.1', 'optimizer_policy' => $data['policy']['version'], 'finance' => $financial['calculation_version'] ?? null, 'finance_policy' => $policy?->version, 'demand' => array_values(array_unique(array_map(fn ($r) => $r['input']['forecast_source'], $data['rows'])))];
            $data['source_refs'] = ['products' => $ids, 'purchase_orders' => $orders->pluck('id')->all(), 'shipments' => $ships->pluck('id')->all(), 'financial_accounts' => array_column($financial['cash']['accounts'], 'id'), 'customer_sales_snapshot' => CustomerSalesSnapshot::latest('id')->value('id'), 'demand_models' => InventoryForecastModel::where('status', 'active')->pluck('version')->all()];
            $data['known_predicted'] = ['known' => ['Current scoped available/reserved/held stock, actual PO/shipment references, recorded cash and obligations.'], 'predicted' => ['Frozen qualified demand, estimated incoming dates and V7 payment timing.'], 'assumed' => ['User-entered scenario changes; they never become real transactions.']];
            $data['limitations'] = array_values(array_unique(array_merge($data['limitations'], array_column($financial['health'] ?? [], 'message'))));
            foreach ($data['donors'] as &$donor) {
                $donor['input']['product']['category_id'] = $products[$donor['input']['product']['id']]->category_id;
            }unset($donor);

            return $data;
        });
    }

    private function warehouseShares($ledger, array $ids, array $companyShares, array $warehouses, string $from, string $to): array
    {
        if (! $warehouses || ! $companyShares) {
            return [];
        }
        $canonical = $ledger->groupBy(fn ($l) => $l['sale_key'].':'.$l['product_id']);
        $totals = [];
        $customerTotals = [];
        $out = [];
        $moves = StockMovement::whereIn('product_id', $ids)->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])->whereIn('movement_code', ['daily_sale', 'invoice_sale'])->where('type', 'out')->limit(20001)->get();
        abort_if($moves->count() > 20000, 422, 'Warehouse customer evidence exceeds the simulation bound. Narrow the product scope.');
        foreach ($moves->groupBy(fn ($m) => ($m->source_type === 'daily_sale' ? 'sale:' : 'invoice:').$m->source_id.':'.$m->product_id) as $key => $group) {
            $sales = $canonical->get($key);
            if (! $sales) {
                continue;
            }$quantity = $sales->sum('quantity');
            if (abs($group->sum('quantity') - $quantity) > .001 || ! $group->every(fn ($m) => $m->unit_snapshot === $sales->first()['unit'])) {
                continue;
            }
            foreach ($group as $m) {
                if (! $m->warehouse_id) {
                    continue;
                }$scope = $m->product_id.':'.$m->warehouse_id;
                $totals[$scope] = ($totals[$scope] ?? 0) + (float) $m->quantity;
                $c = $sales->first()['customer_id'];
                if ($c) {
                    $customerTotals[$scope.':'.$c] = ($customerTotals[$scope.':'.$c] ?? 0) + (float) $m->quantity;
                }
            }
        }
        foreach ($companyShares as $s) {
            foreach ($warehouses as $w) {
                $key = $s['product_id'].':'.$w;
                if (($totals[$key] ?? 0) <= 0) {
                    continue;
                }$quantity = $customerTotals[$key.':'.$s['customer_id']] ?? 0;
                $out[] = $s + ['warehouse_id' => $w];
                $last = array_key_last($out);
                $out[$last]['share'] = $quantity / $totals[$key];
                $out[$last]['observed_quantity'] = $quantity;
                $out[$last]['source'] = 'canonical_sales_reconciled_to_warehouse_movements';
            }
        }

        return $out;
    }
}
