<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CustomerDebtService;
use App\Services\DatedFinancialReconciliation;
use App\Services\LandedCostService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DC2DatedReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function context(): void
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        app(AccountingService::class)->initialize();
    }

    private function clock(string $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    public function test_dated_customer_and_advance_controls_do_not_use_later_current_balances(): void
    {
        $this->context();
        $service = app(CustomerDebtService::class);
        $customer = Customer::create($this->tenantAttributes(['name' => 'Dated customer', 'current_debt' => 0, 'current_credit' => 0]));
        $service->addDebt($customer, ['amount' => 3700, 'transaction_date' => '2026-09-01', 'idempotency_key' => 'dated-sale']);
        $service->recordPayment($customer, ['amount' => 3400, 'transaction_date' => '2026-09-02', 'payment_method' => 'cash', 'idempotency_key' => 'dated-payment']);
        $service->addDebt($customer, ['amount' => 1000, 'transaction_date' => '2026-10-01', 'idempotency_key' => 'later-sale']);
        $service->recordPayment($customer, ['amount' => 700, 'transaction_date' => '2026-10-02', 'payment_method' => 'cash', 'idempotency_key' => 'later-payment']);
        $advance = Customer::create($this->tenantAttributes(['name' => 'Dated advance', 'current_debt' => 0, 'current_credit' => 0]));
        $service->recordPayment($advance, ['amount' => 1000, 'transaction_date' => '2026-09-03', 'payment_method' => 'cash', 'idempotency_key' => 'dated-advance']);
        $service->addDebt($advance, ['amount' => 1400, 'transaction_date' => '2026-10-03', 'idempotency_key' => 'advance-sale']);
        $before = JournalEntry::count();
        $report = app(DatedFinancialReconciliation::class)->report('2026-09-30');
        $rows = collect($report['rows'])->keyBy('control');
        $this->assertTrue($report['all_reconciled'], json_encode($report));
        $this->assertSame('300.00', $rows['accounts_receivable']['operational_amount']);
        $this->assertSame('1000.00', $rows['customer_advances']['operational_amount']);
        $this->assertSame('4400.00', $rows['cash_bank']['operational_amount']);
        $this->assertSame('600.00', $customer->fresh()->current_debt);
        $this->assertSame($before, JournalEntry::count());
        $this->getJson('/api/accounting/reconciliation?to=2026-09-30')->assertOk()->assertJsonPath('as_of', '2026-09-30')->assertJsonPath('all_reconciled', true);
    }

    public function test_required_landed_cost_journey_e_retains_independent_period_end_inventory_after_later_sale_and_reversal(): void
    {
        $this->context();
        $this->clock('2026-09-10 12:00:00');
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Dated import supplier']));
        $category = Category::create($this->tenantAttributes(['name' => 'Dated fabric']));
        $product = Product::create($this->tenantAttributes(['name' => 'Milano 04', 'sku' => 'DC2-DATED-M', 'category_id' => $category->id, 'supplier_id' => $supplier->id, 'quantity' => 0, 'unit' => 'm', 'price' => 4, 'selling_price' => 4, 'purchase_price' => 2]));
        $po = $this->postJson('/api/purchase-orders', ['supplier_id' => $supplier->id, 'ordered_at' => '2026-09-01', 'status' => 'ordered', 'currency' => 'EUR', 'items' => [['product_id' => $product->id, 'description' => 'Milano 04', 'unit' => 'm', 'quantity' => 8000, 'unit_price' => 2]]])->assertCreated()->json();
        $this->postJson('/api/purchase-orders/'.$po['id'].'/receive', ['received_at' => '2026-09-10 12:00:00', 'items' => [['id' => $po['items'][0]['id'], 'quantity' => 8000]], 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $receipt = GoodsReceipt::firstOrFail();
        $landed = [];
        $this->clock('2026-09-12 12:00:00');
        foreach (['freight' => 850, 'customs' => 420, 'insurance' => 100, 'inland_transport' => 230] as $type => $amount) {
            $draft = app(LandedCostService::class)->createDraft(['goods_receipt_id' => $receipt->id, 'cost_type' => $type, 'amount' => $amount, 'currency' => 'EUR', 'allocation_method' => 'quantity', 'goods_receipt_item_ids' => $receipt->items()->pluck('id')->all(), 'idempotency_key' => (string) Str::uuid()]);
            $landed[] = app(LandedCostService::class)->post($draft);
        }
        $this->assertEquals(17600, $product->fresh()->inventory_value);
        $this->assertEquals(2.2, $product->fresh()->weighted_average_cost);
        $this->clock('2026-10-05 12:00:00');
        $this->postJson('/api/daily-sales', ['sale_date' => '2026-10-05', 'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'unit' => 'm', 'quantity' => 1000, 'unit_price' => 4]]])->assertCreated();
        app(LandedCostService::class)->reverse($landed[0], 'Reviewed cancellation of the freight charge.');
        $before = JournalEntry::count();
        $report = app(DatedFinancialReconciliation::class)->report('2026-09-30');
        $inventory = collect($report['rows'])->firstWhere('control', 'inventory');
        $this->assertSame('17600.00', $inventory['operational_amount']);
        $this->assertSame('17600.00', $inventory['gl_amount']);
        $this->assertTrue($inventory['reconciled'], json_encode($inventory));
        $this->assertTrue(app(AccountingService::class)->reconciliation(false)['all_reconciled']);
        $this->assertSame($before, JournalEntry::count());
        $this->assertSame('2026-10-05', $landed[0]->accountingEntries()->firstOrFail()->reversal_date->toDateString());
        DB::table('landed_cost_accounting_entries')->where('landed_cost_id', $landed[0]->id)->update(['reversal_date' => null, 'reversal_inventory_value_before' => null, 'reversal_inventory_value_after' => null]);
        $unproven = collect(app(DatedFinancialReconciliation::class)->report('2026-09-30')['rows'])->firstWhere('control', 'inventory');
        $this->assertFalse($unproven['reconciled']);
        $this->assertSame('evidence_required',$unproven['status']);
    }
}
