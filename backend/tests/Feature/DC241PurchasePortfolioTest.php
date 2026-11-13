<?php

namespace Tests\Feature;

use App\Models\{Expense, FinancialAccount, Product, ProductSupplier, PurchaseOrder, Supplier, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DC241PurchasePortfolioTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_opening_difference_withholds_combined_obligations_but_preserves_source_subtotal(): void
    {
        $this->actingAsApiUser()->getJson('/api/me')->assertOk();
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Legacy supplier']));
        PurchaseOrder::create($this->tenantAttributes([
            'po_number' => 'PO-LEGACY-PO1', 'supplier_id' => $supplier->id, 'currency' => 'EUR',
            'ordered_at' => today()->subDay(), 'status' => 'confirmed', 'total_amount' => 300000, 'total_paid' => 0,
        ]));
        $customer = \App\Models\Customer::create($this->tenantAttributes(['name' => 'Legacy buyer']));
        $customer->forceFill(['current_debt' => 868.86])->save();
        $product = Product::create($this->tenantAttributes(['name' => 'Legacy fabric', 'sku' => 'LEGACY-F', 'unit' => 'm', 'quantity' => 0, 'price' => 4]));
        $this->postJson('/api/procurement/purchase-comparison', [
            'budget' => 10000, 'lines' => [['product_id' => $product->id, 'quantity' => 500]],
        ])->assertOk()
            ->assertJsonPath('financial.combined_known_obligations', null)
            ->assertJsonPath('financial.known_unbilled_po_exposure', null)
            ->assertJsonPath('financial.partial_po_source_subtotal', '300000.00')
            ->assertJsonCount(0, 'financial.commitment_evidence.resolved')
            ->assertJsonPath('financial.commitment_evidence.partial.0.recorded_order_residual', '300000.00')
            ->assertJsonPath('financial.posted_supplier_bills', null)
            ->assertJsonPath('financial.evidence_readiness.unreconciled_controls.0', 'accounts_receivable')
            ->assertJsonPath('financial.spendable_cash', null)
            ->assertJsonPath('financial.evidence_readiness.status', 'blocked');
        $this->postJson('/api/intelligence-assistant/ask', [
            'language' => 'en', 'question' => 'Show the purchasing plan for "Legacy fabric" at 500 meters.',
        ])->assertOk()->assertJsonPath('state', 'answered')
            ->assertJsonPath('tools.0.tool', 'get_purchase_portfolio')
            ->assertSee('Finance must reconcile the records', false);
        $this->postJson('/api/intelligence-assistant/ask', [
            'language' => 'en', 'question' => 'How much do we owe suppliers?',
        ])->assertOk()->assertJsonPath('tools.0.tool', 'get_accounting_payables')
            ->assertSee('Total supplier obligations cannot be confirmed', false);
        $this->postJson('/api/intelligence-assistant/ask', [
            'language' => 'en', 'question' => 'What can I safely conclude from PO-LEGACY-PO1?',
        ])->assertOk()->assertJsonPath('tools.0.tool', 'get_purchase_order')
            ->assertSee('Remaining from the recorded order total and payments: 300000.00 EUR', false);
        foreach (['en' => 'Can I spend €20,000 on new stock?', 'sq' => 'A kemi pare me porosit mall?'] as $language => $question) {
            $this->postJson('/api/intelligence-assistant/ask', compact('language', 'question'))->assertOk()
                ->assertJsonPath('intent', 'finance_purchase_capacity')->assertJsonPath('cards.0.metrics', []);
        }
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_undated_foreign_order_withholds_the_aggregate_without_counting_another_tenant(): void
    {
        $this->actingAsApiUser()->getJson('/api/me')->assertOk();
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Foreign supplier']));
        $order = PurchaseOrder::create($this->tenantAttributes([
            'po_number' => 'FX-PO', 'supplier_id' => $supplier->id, 'currency' => 'USD',
            'ordered_at' => today()->subDay(), 'status' => 'confirmed', 'total_amount' => 100, 'total_paid' => 0,
            'exchange_rate' => '0.9', 'exchange_rate_date' => null,
        ]));
        $finance = app(\App\Services\PurchasePortfolioFinance::class);
        $result = $finance->read('EUR');
        $this->assertSame('partial', $result['evidence_readiness']['status']);
        $this->assertNull($result['combined_known_obligations']);
        $this->assertSame('missing_po_fx', $result['commitment_evidence']['review'][0]['reason']);
        $this->assertSame($order->id, $result['commitment_evidence']['review'][0]['order_id']);

        $company = \App\Models\Company::factory()->create();
        $other = User::factory()->create(['company_id' => $company->id, 'role' => 'admin', 'email_verified_at' => now()]);
        $this->renewApiSession($other);
        $this->getJson('/api/me')->assertOk();
        $result = $finance->read('EUR');
        $this->assertSame('verified', $result['evidence_readiness']['status']);
        $this->assertSame('0.00', $result['combined_known_obligations']);
        $this->assertNull($result['recorded_cash']);
        $this->assertNull($result['spendable_cash']);
        $this->assertSame([], $result['commitment_evidence']['review']);
    }

    public function test_portfolio_discloses_unpriced_items_and_deducts_matched_bills_once(): void
    {
        $this->actingAsApiUser()->getJson('/api/me')->assertOk();
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Portfolio supplier']));
        $product = Product::create($this->tenantAttributes(['name' => 'Portfolio linen', 'sku' => 'PORT-LINEN', 'unit' => 'm', 'quantity' => 0, 'price' => 4]));
        $other = Product::create($this->tenantAttributes(['name' => 'Unpriced fabric', 'sku' => 'PORT-OTHER', 'unit' => 'm', 'quantity' => 0, 'price' => 4]));
        ProductSupplier::create($this->tenantAttributes(['product_id' => $product->id, 'supplier_id' => $supplier->id,
            'purchase_price' => '2.00', 'currency' => 'EUR', 'minimum_order_quantity' => 10, 'pack_size' => 10, 'is_active' => true]));
        $cash = FinancialAccount::create($this->tenantAttributes(['name' => 'Portfolio cash', 'type' => 'cash', 'currency' => 'EUR',
            'opening_balance' => 1000, 'opening_date' => today()->subDay(), 'is_active' => true]));
        $order = fn ($reference, $amount) => PurchaseOrder::create($this->tenantAttributes([
            'po_number' => $reference, 'supplier_id' => $supplier->id, 'currency' => 'EUR', 'exchange_rate' => 1,
            'ordered_at' => today()->subDay(), 'status' => 'confirmed', 'total_paid' => 0, 'total_amount' => $amount,
        ]));
        $linked = $order('PORT-PO1', 200);
        $order('PORT-PO2', 50);
        $independentSupplier = Supplier::create($this->tenantAttributes(['name' => 'Independent bill supplier']));
        $bill = fn ($reference, $net, $gross, $po = null) => Expense::create($this->tenantAttributes([
            'supplier_id' => ($po ? $supplier : $independentSupplier)->id, 'purchase_order_id' => $po?->id,
            'vendor_name' => ($po ? $supplier : $independentSupplier)->name,
            'vendor_key' => hash('sha256', ($po ? $supplier : $independentSupplier)->name), 'document_type' => 'purchase_invoice',
            'document_number_normalized' => $reference, 'document_number' => $reference, 'category' => 'other',
            'invoice_date' => today()->subDay(), 'received_date' => today()->subDay(), 'due_date' => today()->addDays(5),
            'status' => 'posted', 'match_status' => $po ? 'matched' : 'unmatched', 'currency' => 'EUR', 'exchange_rate' => 1,
            'net_amount' => $net, 'net_amount_eur' => $net, 'vat_amount' => $gross - $net,
            'gross_amount' => $gross, 'gross_amount_eur' => $gross,
        ]));
        $matched = $bill('PORT-BILL1', 80, 96, $linked);
        $independent = $bill('PORT-BILL2', 30, 30);
        $accounting = app(\App\Services\AccountingService::class);
        $accounting->postFinancialOpening($cash);
        $accounting->postExpense($matched);
        $accounting->postExpense($independent);
        $journalCount = \App\Models\JournalEntry::count();
        $payload = ['budget' => 90, 'buffer' => 20, 'lines' => [
            ['product_id' => $product->id, 'quantity' => 50, 'supplier_id' => $supplier->id],
            ['product_id' => $other->id, 'quantity' => 30],
        ]];
        $this->postJson('/api/procurement/purchase-comparison', $payload)->assertOk()
            ->assertJsonPath('strategy', 'quantity_sensitivity')
            ->assertJsonPath('financial.posted_supplier_bills', '126.00')
            ->assertJsonPath('financial.linked_supplier_bills', '96.00')
            ->assertJsonPath('financial.known_unbilled_po_exposure', '170.00')
            ->assertJsonPath('financial.combined_known_obligations', '296.00')
            ->assertJsonPath('financial.evidence_readiness.status', 'verified')
            ->assertJsonPath('financial.spendable_cash', null)
            ->assertJsonPath('scenarios.1.known_material_cost', '100.00')
            ->assertJsonPath('scenarios.1.unpriced_count', 1)
            ->assertJsonPath('scenarios.1.remaining_envelope_before_extras', null)
            ->assertJsonPath('scenarios.1.known_materials_exceed_envelope', true)
            ->assertJsonPath('scenarios.1.recorded_cash_less_obligations_buffer_and_known_materials', '584.00');
        $this->postJson('/api/procurement/purchase-comparison', ['product_id' => $product->id, 'quantity' => 13])
            ->assertOk()->assertJsonPath('suppliers.0.catalogue_next_valid_base_quantity', 20)
            ->assertJsonPath('suppliers.0.catalogue_estimate', null);
        $warehouse = \App\Models\Warehouse::create($this->tenantAttributes(['name' => 'Planning warehouse', 'code' => 'PORT-W', 'is_active' => true]));
        $date = today()->addDays(20)->toDateString();
        $conversation = $this->postJson('/api/intelligence-assistant/ask', ['question' =>
            'Compare suppliers for "Portfolio linen" at 50 metres in warehouse "Planning warehouse" with supplier "Portfolio supplier" by '.$date.'.'])
            ->assertOk()->assertJsonPath('tools.0.arguments.warehouse_id', $warehouse->id)
            ->assertJsonPath('tools.0.arguments.supplier_id', $supplier->id)->assertJsonPath('tools.0.arguments.required_by', $date)
            ->json('conversation_id');
        $this->postJson('/api/intelligence-assistant/ask', ['conversation_id' => $conversation, 'question' => 'What if I order 40 metres?'])
            ->assertOk()->assertJsonPath('tools.0.arguments.quantity', 40)
            ->assertJsonPath('tools.0.arguments.warehouse_id', $warehouse->id)
            ->assertJsonPath('tools.0.arguments.supplier_id', $supplier->id)->assertJsonPath('tools.0.arguments.required_by', $date);

        $matched->update(['match_status' => 'exception']);
        $this->postJson('/api/procurement/purchase-comparison', $payload)->assertOk()
            ->assertJsonPath('financial.combined_known_obligations', null)
            ->assertJsonPath('financial.evidence_readiness.status', 'partial')
            ->assertJsonPath('financial.unresolved_po_overlaps_or_fx', 1)
            ->assertJsonPath('scenarios.1.recorded_cash_less_obligations_buffer_and_known_materials', null);
        $this->assertDatabaseCount('purchase_orders', 2);
        $this->assertDatabaseCount('purchase_requests', 0);
        $this->assertDatabaseCount('journal_entries', $journalCount);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_purchasing_and_finance_permissions_must_both_authorize_the_portfolio(): void
    {
        $this->actingAsApiUser()->getJson('/api/me')->assertOk();
        $this->apiCompany->update(['role_permissions' => ['staff' => ['inventory.view', 'procurement.view', 'supplier_catalogue.view']]]);
        $staff = User::factory()->create(['company_id' => $this->apiCompany->id, 'role' => 'staff', 'email_verified_at' => now()]);
        $this->renewApiSession($staff);
        $this->postJson('/api/procurement/purchase-comparison', ['lines' => [['product_id' => 1, 'quantity' => 5]]])->assertForbidden();
        $this->postJson('/api/intelligence-assistant/ask', ['question' => 'Show the purchasing plan for "Milano 04" at 500 meters.'])
            ->assertOk()->assertJsonPath('state', 'permission_denied')->assertJsonCount(0, 'tools');
    }

    public function test_strategy_uses_uncovered_confirmed_orders_and_separates_unqualified_lines(): void
    {
        $this->actingAsApiUser()->getJson('/api/me')->assertOk();
        $product = Product::create($this->tenantAttributes(['name' => 'Committed fabric', 'sku' => 'PORT-NEED', 'unit' => 'm', 'quantity' => 0, 'price' => 4]));
        $other = Product::create($this->tenantAttributes(['name' => 'Unqualified fabric', 'sku' => 'PORT-MAYBE', 'unit' => 'm', 'quantity' => 0, 'price' => 4]));
        $customer = \App\Models\Customer::create($this->tenantAttributes(['name' => 'Portfolio fixture buyer']));
        $order = \App\Models\SalesOrder::create($this->tenantAttributes([
            'customer_id' => $customer->id, 'order_number' => 'PORT-SO', 'status' => 'confirmed', 'order_date' => today(),
            'confirmed_at' => now(), 'currency' => 'EUR', 'idempotency_key' => 'portfolio-demand', 'request_fingerprint' => str_repeat('a', 64),
        ]));
        $order->items()->create($this->tenantAttributes([
            'product_id' => $product->id, 'unit' => 'm', 'quantity' => 175, 'base_quantity' => 175, 'unit_price' => 4, 'line_total' => 700,
        ]));
        $this->postJson('/api/procurement/purchase-comparison', ['lines' => [
            ['product_id' => $product->id, 'quantity' => 100], ['product_id' => $other->id, 'quantity' => 40],
        ]])->assertOk()->assertJsonPath('strategy', 'confirmed_shortfall_review')
            ->assertJsonPath('lines.0.firm_shortfall_now', 175)
            ->assertJsonPath('scenarios.0.lines.0.quantity', 50)
            ->assertJsonPath('scenarios.1.lines.0.quantity', 100)
            ->assertJsonPath('scenarios.2.lines.0.quantity', 150)
            ->assertJsonPath('scenarios.2.lines.1.reason', 'needs_separate_review')
            ->assertJsonPath('scenarios.2.lines.1.cost', null);
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
