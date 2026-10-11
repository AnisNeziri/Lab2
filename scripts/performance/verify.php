<?php

require __DIR__.'/bootstrap.php';
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

$statePath = realpath($argv[2] ?? '');
if (! $statePath || ! str_starts_with($statePath, $owned.DIRECTORY_SEPARATOR) || is_link($argv[2])) {
    throw new RuntimeException('Owned state required.');
}
$s = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
if ($s['company_id'] !== $owner->company_id) {
    throw new RuntimeException('Wrong fixture tenant.');
}
$s['order_id'] = $s['order_id'] ?? SalesOrder::where('idempotency_key', $s['order_key'])->value('id');
$checks = [];
$details = [];
$p = Product::findOrFail($s['product_id']);
$c = Customer::findOrFail($s['customer_id']);
$details['quantity'] = (float) $p->quantity;
$details['expected_quantity'] = (float) ($s['before_quantity'] - 26 - 4 + 2);
$checks['stock_delta_exact'] = $details['quantity'] === $details['expected_quantity'];
$checks['warehouse_delta_exact'] = (float) DB::table('warehouse_stock')->where('company_id', $owner->company_id)->where('product_id', $p->id)->sum('quantity') === $details['expected_quantity'];
$checks['single_same_key_stock_effect'] = DB::table('stock_movements')->where('company_id', $owner->company_id)->where('idempotency_key', $s['stock_key'])->count() === 1;
$checks['all_25_unique_stock_effects'] = DB::table('stock_movements')->where('company_id', $owner->company_id)->whereIn('idempotency_key', $s['unique_stock_keys'])->count() === 25;
$checks['single_order'] = SalesOrder::where('idempotency_key', $s['order_key'])->count() === 1;
$checks['single_dispatch'] = DB::table('outbound_dispatches')->where('sales_order_id', $s['order_id'])->count() === 1;
$checks['reservation_released'] = (float) DB::table('warehouse_stock')->where('company_id', $owner->company_id)->where('product_id', $p->id)->sum('reserved_quantity') === 0.0;
$details['debt'] = (float) $c->current_debt;
$details['advance'] = (float) $c->current_credit;
$checks['financial_balance_exact'] = $details['debt'] === 17.0 && $details['advance'] === 0.0;
$checks['single_customer_payment'] = DB::table('customer_debt_transactions')->where('company_id', $owner->company_id)->where('idempotency_key', $s['payment_key'])->count() === 1;
$checks['single_receipt'] = DB::table('goods_receipts')->where('company_id', $owner->company_id)->where('idempotency_key', $s['receipt_key'])->count() === 1;
$checks['single_po_payment'] = DB::table('purchase_order_payments')->where('purchase_order_id', $s['po_id'])->where('idempotency_key', $s['po_payment_key'])->count() === 1;
$po = PurchaseOrder::findOrFail($s['po_id']);
$checks['po_payment_exact'] = (float) $po->total_paid === 4.0;
$checks['po_received_exact'] = (float) DB::table('purchase_order_items')->where('id', $s['po_item_id'])->value('received_base_quantity') === 2.0;
$journals = DB::table('journal_entries')->where('company_id', $owner->company_id)->whereIn('source_id', [$s['order_id'], $s['po_id'], $s['customer_id']])->get(['id', 'status', 'total_debit', 'total_credit']);
$checks['journal_entries_balanced'] = $journals->every(fn ($j) => $j->total_debit === $j->total_credit);
echo json_encode(['status' => in_array(false, $checks, true) ? 'FAIL' : 'PASS', 'checks' => $checks, 'details' => $details, 'qualification' => 'Exact deltas on fresh authoritative records; bulk performance history is not an accounting certification.']);
exit(in_array(false,$checks,true) ? 1 : 0);
