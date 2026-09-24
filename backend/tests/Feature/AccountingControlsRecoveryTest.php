<?php

namespace Tests\Feature;

use App\Models\{AccountingException, AccountingPeriod, AccountingRecoveryAttempt, Customer, DailySale, JournalEntry, User};
use App\Services\{AccountingRecoveryService, AccountingService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingControlsRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function accounting(): AccountingService
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(User::query()->where('company_id', $this->apiCompany->id)->firstOrFail());
        $service = app(AccountingService::class);
        $service->initialize();
        return $service;
    }

    public function test_company_matching_tolerances_are_saved_and_audited(): void
    {
        $service = $this->accounting();
        $values = $service->saveControls([
            'supplier_match_quantity_tolerance' => .25,
            'supplier_match_price_tolerance' => 1.125,
            'supplier_match_tax_tolerance' => .50,
        ]);

        $this->assertSame('0.250', $values['supplier_match_quantity_tolerance']);
        $this->assertSame('1.1250', $values['supplier_match_price_tolerance']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounting.controls.updated']);
    }

    public function test_customer_advances_reconcile_separately_from_receivables(): void
    {
        $service = $this->accounting();
        $accounts = collect($service->accounts())->keyBy('code');
        Customer::create($this->tenantAttributes(['name' => 'Advance Customer', 'current_credit' => 40, 'current_debt' => 0]));
        $service->createAndPost([
            'posting_date' => now()->toDateString(), 'description' => 'Customer advance',
            'source_module' => 'customer_credit', 'source_key' => 'test-customer-advance',
            'lines' => [
                ['accounting_account_id' => $accounts['1000']->id, 'debit' => 40],
                ['accounting_account_id' => $accounts['2200']->id, 'credit' => 40],
            ],
        ]);

        $result = $service->reconciliation(false);
        $this->assertSame('40.00', $result['rows']['customer_advances']['operational_amount']);
        $this->assertSame('40.00', $result['rows']['customer_advances']['gl_amount']);
        $this->assertTrue($result['rows']['customer_advances']['reconciled']);
        $this->assertSame('0.00', $result['rows']['accounts_receivable']['operational_amount']);
    }

    public function test_missing_sale_posting_can_be_recovered_once_with_history(): void
    {
        $service = $this->accounting();
        $sale = DailySale::create($this->tenantAttributes([
            'sale_number' => 'RECOVERY-1', 'sale_date' => now()->toDateString(), 'status' => 'finalized',
            'total_amount' => 25, 'total_quantity' => 1, 'created_by' => Auth::id(),
        ]));
        $service->scanIntegrity();
        $exception = AccountingException::query()->where('exception_key', 'missing-posting:daily-sale:'.$sale->id)->firstOrFail();

        $attempt = app(AccountingRecoveryService::class)->retry($exception);
        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame('resolved', $exception->fresh()->status);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'daily_sale')->where('source_id', $sale->id)->count());
        $this->assertDatabaseHas('accounting_recovery_attempts', ['id' => $attempt->id, 'status' => 'succeeded']);

        $this->expectException(ValidationException::class);
        app(AccountingRecoveryService::class)->retry($exception->fresh());
    }

    public function test_period_close_requires_readiness_and_ready_period_closes(): void
    {
        $service = $this->accounting();
        $accounts = collect($service->accounts())->keyBy('code');
        $period = $service->savePeriod(['name' => 'Ready period', 'starts_at' => '2026-09-01', 'ends_at' => '2026-09-30']);
        $draft = $service->createDraft([
            'posting_date' => '2026-09-10', 'description' => 'Pending review',
            'lines' => [
                ['accounting_account_id' => $accounts['1000']->id, 'debit' => 10],
                ['accounting_account_id' => $accounts['3000']->id, 'credit' => 10],
            ],
        ]);
        $this->assertFalse($service->periodReadiness($period)['ready_to_close']);
        try {
            $service->setPeriodStatus($period, 'closed', 'Attempted close with pending journal.');
            $this->fail('An unready period was closed.');
        } catch (ValidationException) {
            $this->assertSame('open', $period->fresh()->status);
        }

        $draft->delete();
        $readiness = $service->periodReadiness($period);
        $this->assertTrue($readiness['ready_to_close']);
        $this->assertStringContainsString('dynamically', $readiness['retained_earnings_policy']);
        $this->assertSame('closed', $service->setPeriodStatus($period, 'closed', 'All readiness checks completed.')->status);
    }
}
