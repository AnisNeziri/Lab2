<?php

use App\Models\CustomerSalesSnapshot;
use App\Models\FinancialIntelligenceSnapshot;
use App\Services\FinancialForecastMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


/** Expanded frozen read shapes, deliberately separate from actual ML training/evaluation. */
function addPerformanceEvidence(int $company): array
{
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 2).'/backend/tests/Fixtures/PerformanceReadEvidence.json'), true, 512, JSON_THROW_ON_ERROR);
    $customers = DB::table('customers')->where('company_id', $company)->orderBy('id')->get(['id', 'name']);
    $products = DB::table('products')->where('company_id', $company)->orderBy('id')->get(['id', 'name']);
    $orders = DB::table('purchase_orders')->where('company_id', $company)->orderBy('id')->get(['id', 'po_number']);
    $tag = 'PERFORMANCE / TEST DATA: frozen read shapes; no predictive accuracy or accounting certification.';
    $e = $fixture['finance'];
    $e['as_of'] = gmdate('Y-m-d');
    $e['cutoff'] = gmdate('c');
    $e['confidence'] = 'limited';
    $e['local_model'] = ['state' => 'INSUFFICIENT_DATA', 'qualification' => $tag];
    $e['receivables'] = [];
    $e['customers'] = [];
    $e['commitments'] = [];
    $e['payable_observations'] = [];
    foreach ($customers as $i => $customer) {
        $r = $fixture['finance']['receivables'][0];
        $r = array_replace($r, ['key' => 'perf-debt-'.$customer->id, 'customer_id' => $customer->id, 'customer' => $customer->name, 'reference' => 'PERF-DEBT-'.$i, 'amount' => '5.00', 'original_amount' => '5.00', 'allocated_reduction' => '0.00', 'confidence' => 'limited', 'qualification' => $tag, 'url' => '/customer-debts?customer='.$customer->id]);
        $e['receivables'][] = $r;
        $r = $fixture['finance']['customers'][0];
        $r = array_replace($r, ['id' => $customer->id, 'name' => $customer->name, 'debt' => '5.00', 'advance' => '0.00', 'ledger_outstanding' => '5.00', 'url' => '/customer-debts?customer='.$customer->id]);
        $e['customers'][] = $r;
    }
    foreach ($orders as $order) {
        $r = $fixture['finance']['commitments'][0];
        $e['commitments'][] = array_replace($r, ['key' => 'perf-po-'.$order->id, 'reference' => $order->po_number, 'purchase_order_id' => $order->id, 'source_id' => $order->id, 'amount' => '30.00', 'url' => '/purchase-orders?purchase_order='.$order->id, 'documented_terms' => $tag]);
    }
    $e['inventory']['products'] = [];
    $e['inventory']['total'] = (string) ($products->count() * 300);
    $e['scenario_options']['products'] = [];
    $e['scenario_options']['suppliers'] = [];
    foreach ($products as $product) {
        $r = $fixture['finance']['inventory']['products'][0];
        $e['inventory']['products'][] = array_replace($r, ['id' => $product->id, 'name' => $product->name, 'value' => '300.00']);
        $e['scenario_options']['products'][] = ['id' => $product->id, 'name' => $product->name];
    }
    foreach (DB::table('suppliers')->where('company_id', $company)->get(['id', 'name']) as $supplier) {
        $e['scenario_options']['suppliers'][] = ['id' => $supplier->id, 'name' => $supplier->name];
    }
    $e['totals'] = ['EUR' => ['recorded_receivables' => (string) ($customers->count() * 5), 'recorded_advances' => '0.00', 'committed_payables' => (string) ($orders->count() * 30)]];
    $e['health'] = [['code' => 'PERFORMANCE_ONLY', 'message' => $tag, 'count' => 1, 'url' => null]];
    // The existing math shapes forecasts from fixture amounts. This is not an evaluated prediction.
    $forecasts = [];
    foreach ([7, 30, 60, 90] as $h) {
        $forecasts[$h] = app(FinancialForecastMath::class)->calculate($e, $h);
    }
    FinancialIntelligenceSnapshot::firstOrCreate(['fingerprint' => hash('sha256', 'performance-finance:'.$company)], ['version' => (string) Str::uuid(), 'as_of' => today(), 'evidence_cutoff' => now(), 'evidence' => $e, 'forecast' => $forecasts]);
    $e = $fixture['customers'];
    $e['as_of'] = gmdate('Y-m-d');
    $e['profiles'] = [];
    $e['opportunities'] = [];
    $e['observations'] = [];
    $e['health'] = [];
    $e['qualification'] = $tag;
    $e['local_model'] = ['state' => 'INSUFFICIENT_DATA', 'algorithm' => 'shape_fixture', 'customers' => 0, 'trained_model' => null];
    $e['affinity'] = ['products' => [], 'categories' => []];
    $e['resource_trends'] = ['products' => [], 'categories' => []];
    foreach ($customers as $i => $customer) {
        $r = $fixture['customers']['profiles'][0];
        $r = array_replace($r, ['id' => $customer->id, 'name' => $customer->name, 'state' => $i % 2 ? 'INACTIVE' : 'ACTIVE', 'purchase_count' => 2, 'products' => [], 'categories' => [], 'recent_history' => array_fill(0, 10, ['date' => gmdate('Y-m-d'), 'value' => '5.00', 'quantity' => 1, 'products' => []]), 'diagnostic_dates' => [], 'limitations' => [$tag], 'financial_context' => ['state' => 'available', 'review_required' => false, 'qualification' => $tag]]);
        $r['cadence']['eligible'] = false;
        $r['trend']['supported'] = false;
        $e['profiles'][] = $r;
    }
    $e['concentration'] = ['from' => gmdate('Y-m-d'), 'to' => gmdate('Y-m-d'), 'net_sales' => '0.00', 'groups' => ['customers' => [], 'products' => [], 'categories' => []], 'top_shares' => [1 => null, 5 => null, 10 => null], 'qualification' => $tag];
    $e['canonical_baskets'] = $customers->count() * 2;
    CustomerSalesSnapshot::firstOrCreate(['fingerprint' => hash('sha256', 'performance-customers:'.$company)], ['version' => (string) Str::uuid(), 'as_of' => today(), 'evidence_cutoff' => now(), 'evidence' => $e]);
    // Legacy local tracking shape. External polling is disabled by every harness.
    DB::table('shipments')->where('company_id', $company)->where('tracking_number', 'like', 'PERF-SHIP-%')->update(['tracking_mode' => 'aisstream', 'tracking_provider' => 'aisstream', 'transport_mode' => 'sea', 'last_refreshed_at' => now(), 'notes' => 'PERFORMANCE / TEST DATA; frozen mock tracking; external providers disabled.']);

    return ['customer_profiles' => $customers->count(), 'receivables' => $customers->count(), 'commitments' => $orders->count(), 'inventory_evidence' => $products->count(), 'qualification' => $tag];
}
