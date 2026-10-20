<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CustomerDebtService;
use App\Services\ReceivableReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DC2ReceivableReportsTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        app(AccountingService::class)->initialize();

        return [Customer::create($this->tenantAttributes(['name' => 'Statement customer', 'current_debt' => 0, 'current_credit' => 0])), app(CustomerDebtService::class)];
    }

    private function invoice(Customer $customer, array $overrides = []): Invoice
    {
        return Invoice::create($this->tenantAttributes([...[
            'customer_id' => $customer->id, 'customer_name' => $customer->name, 'invoice_number' => 'INV-REPORT',
            'document_type' => 'invoice', 'status' => 'issued', 'currency' => 'EUR', 'invoice_date' => '2026-08-01',
            'issued_at' => '2026-08-01 10:00:00', 'grand_total' => 300, 'total_amount' => 300, 'total_paid' => 0,
        ], ...$overrides]));
    }

    public function test_unified_aging_preserves_unknown_due_dates_and_advance_separation_without_duplicate_invoice(): void
    {
        [$c, $s] = $this->context();
        $s->recordPayment($c, ['amount' => 1000, 'transaction_date' => '2026-07-01', 'payment_method' => 'cash', 'idempotency_key' => 'advance']);
        $d = $s->addDebt($c, ['amount' => 1400, 'transaction_date' => '2026-08-01', 'due_date' => '2026-08-15', 'idempotency_key' => 'debt']);
        $linked = $this->invoice($c, ['invoice_number' => 'LINKED', 'grand_total' => 1400, 'total_amount' => 1400]);
        DB::table('customer_debt_transactions')->where('id', $d->id)->update(['invoice_id' => $linked->id]);
        $this->invoice($c, ['invoice_number' => 'UNKNOWN', 'due_at' => null]);
        $before = JournalEntry::count();
        $r = $this->getJson('/api/finance/receivables-aging?as_of=2026-09-01')->assertOk()
            ->assertJsonPath('summary.total_outstanding', 700)->assertJsonPath('summary.overdue_total', 400)
            ->assertJsonPath('summary.unknown_due', 300)->assertJsonPath('summary.open_count', 2);
        $this->assertSame('unknown_due', collect($r->json('receivables'))->firstWhere('reference', 'UNKNOWN')['bucket']);
        $this->assertSame($before, JournalEntry::count());
        $this->assertCount(1, $r->json('top_overdue_customers'));
    }

    public function test_statement_opening_and_closing_use_dated_ar_and_advance_movements(): void
    {
        [$c, $s] = $this->context();
        $s->recordPayment($c, ['amount' => 1000, 'transaction_date' => '2026-08-01', 'payment_method' => 'cash', 'idempotency_key' => 'prepay']);
        $s->addDebt($c, ['amount' => 1400, 'transaction_date' => '2026-09-01', 'idempotency_key' => 'sale']);
        $s->recordPayment($c, ['amount' => 100, 'transaction_date' => '2026-09-02', 'payment_method' => 'cash', 'idempotency_key' => 'part']);
        $r = $this->getJson('/api/accounting/ar/customers/'.$c->id.'/statement?from=2026-09-01&to=2026-09-30')->assertOk()
            ->assertJsonPath('opening.receivable', '0.00')->assertJsonPath('opening.advance', '1000.00')
            ->assertJsonPath('closing.receivable', '300.00')->assertJsonPath('closing.advance', '0.00');
        $this->assertCount(2, $r->json('entries.data'));
        $rows = $r->json('entries.data');
        $this->assertEquals(400, $rows[0]['ar_balance']);
        $this->assertEquals(-1000, $rows[0]['advance_delta']);
        $this->assertEquals(300, $rows[1]['ar_balance']);
    }

    public function test_invoice_payment_reversal_restores_historical_statement_and_aging(): void
    {
        [$c] = $this->context();
        $invoice = $this->invoice($c, ['due_at' => '2026-08-20']);
        PaymentTransaction::create($this->tenantAttributes(['invoice_id' => $invoice->id, 'user_id' => Auth::id(), 'amount' => 100,
            'status' => 'reversed', 'payment_method' => 'cash', 'payment_date' => '2026-08-05', 'reversed_at' => '2026-09-02 10:00:00']));
        $report = app(ReceivableReports::class);
        $this->assertSame('200.00', $report->statement($c, '2026-08-01', '2026-08-31', 'EUR')['closing']['receivable']);
        $this->assertSame('300.00', $report->statement($c, '2026-09-01', '2026-09-30', 'EUR')['closing']['receivable']);
        $this->assertEquals(200, $report->aging('2026-08-31')['summary']['total_outstanding']);
        $this->assertEquals(300, $report->aging('2026-09-30')['summary']['total_outstanding']);
    }

    public function test_aging_filters_and_page_two_statement_balances_are_bounded_and_do_not_cross_company(): void
    {
        [$c, $s] = $this->context();
        for ($i = 0; $i < 55; $i++) {
            $s->addDebt($c, ['amount' => 10, 'transaction_date' => '2026-09-01', 'idempotency_key' => 'row-'.$i]);
        }
        $this->invoice($c, ['invoice_number' => 'USD-REVIEW', 'currency' => 'USD', 'grand_total' => 100, 'total_amount' => 100]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $r = $this->getJson('/api/finance/receivables-aging?as_of=2026-09-30&currency=EUR&customer_id='.$c->id)->assertOk()
            ->assertJsonPath('summary.total_outstanding', 550)->assertJsonPath('pagination.total', 55);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(50, $r->json('receivables'));
        $this->assertLessThan(30, $queries);
        $r = $this->getJson('/api/accounting/ar/customers/'.$c->id.'/statement?from=2026-09-01&to=2026-09-30&page=2')->assertOk();
        $this->assertCount(5, $r->json('entries.data'));
        $this->assertEquals(510, $r->json('entries.data.0.ar_balance'));
        $this->getJson('/api/finance/receivables-aging?as_of=2026-09-30&salesperson_id=999999')->assertOk()->assertJsonPath('summary.total_outstanding', 0);
        $other = User::factory()->create(['company_id' => Company::factory()->create()->id, 'role' => 'admin', 'api_token' => hash('sha256', 'dc2-other-tenant')]);
        Auth::setUser($other);
        $this->withHeader('Authorization', 'Bearer dc2-other-tenant');
        $this->getJson('/api/accounting/ar/customers/'.$c->id.'/statement?from=2026-09-01&to=2026-09-30')->assertNotFound();
        $this->getJson('/api/finance/receivables-aging?as_of=2026-09-30')->assertOk()->assertJsonPath('summary.total_outstanding', 0);
    }
}
