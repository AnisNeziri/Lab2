<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CustomerDebtService;
use App\Services\FinancialAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DC2CustomerLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        $accounting = app(AccountingService::class);
        $accounting->initialize();
        $customer = Customer::create($this->tenantAttributes(['name' => 'DC2 settlement customer', 'current_debt' => 0, 'current_credit' => 0]));

        return [$customer, app(CustomerDebtService::class), $accounting];
    }

    private function debt($service, $customer, $amount, $key)
    {
        return $service->addDebt($customer, ['amount' => $amount, 'transaction_date' => '2026-09-10', 'due_date' => '2026-09-30', 'idempotency_key' => $key, 'reference_number' => $key]);
    }

    private function payment($service, $customer, $amount, $key, $bank = null)
    {
        return $service->recordPayment($customer, ['amount' => $amount, 'transaction_date' => '2026-09-11', 'payment_method' => $bank ? 'bank_transfer' : 'cash', 'financial_account_id' => $bank?->id, 'idempotency_key' => $key]);
    }

    public function test_required_journey_a_partial_payment_lifecycle_reconciles_without_duplicate_cash(): void
    {
        [$c,$s,$a] = $this->context();
        $bank = app(FinancialAccountService::class)->createAccount(['name' => 'Journey A bank', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => 0, 'opening_date' => '2026-09-01', 'is_active' => true]);
        $this->debt($s, $c, 3700, 'sale-A');
        $this->payment($s, $c, 3400, 'payment-A', $bank);
        $this->assertSame('300.00', $c->fresh()->current_debt);
        $this->debt($s, $c, 1000, 'sale-B');
        $this->assertSame('1300.00', $c->fresh()->current_debt);
        $this->payment($s, $c, 700, 'payment-B', $bank);
        $this->assertSame('600.00', $c->fresh()->current_debt);
        $r = $a->reconciliation(false);
        $this->assertTrue($r['all_reconciled']);
        $this->assertSame('600.00', $r['rows']['accounts_receivable']['gl_amount']);
        $this->assertSame('4100.00', $r['rows']['cash_bank']['gl_amount']);
        $this->assertSame(4100.0, app(FinancialAccountService::class)->balance($bank));
        $this->assertSame(2, $bank->transactions()->count());
    }

    public function test_required_journey_b_advance_applies_without_second_cash_or_revenue(): void
    {
        [$c,$s,$a] = $this->context();
        $this->payment($s, $c, 1000, 'advance');
        $this->assertSame('1000.00', $c->fresh()->current_credit);
        $this->assertSame('0.00', $c->fresh()->current_debt);
        $this->debt($s, $c, 1400, 'sale-after-advance');
        $this->assertSame('400.00', $c->fresh()->current_debt);
        $this->assertSame('0.00', $c->fresh()->current_credit);
        $r = $a->reconciliation(false);
        $this->assertTrue($r['all_reconciled']);
        $this->assertSame('1000.00', $r['rows']['cash_bank']['gl_amount']);
        $this->assertSame('1400.00', $a->profitAndLoss(null, '2026-09-30')['revenue']);
        $this->assertSame(2, JournalEntry::where('status', 'posted')->count());
    }

    public function test_reversing_advance_backed_sale_does_not_consume_unrelated_receivable(): void
    {
        [$c,$s,$a] = $this->context();
        $this->payment($s, $c, 1000, 'advance');
        $sale = $this->debt($s, $c, 1400, 'advance-sale');
        $this->debt($s, $c, 600, 'unrelated-sale');
        $s->reverse($sale, 'Correct the first sale only.');
        $this->assertSame('600.00', $c->fresh()->current_debt);
        $this->assertSame('1000.00', $c->fresh()->current_credit);
        $this->assertTrue($a->reconciliation(false)['all_reconciled']);
        $this->assertSame('600.00', $a->profitAndLoss(null, null)['revenue']);
    }

    public function test_reversing_fully_settled_advance_backed_sale_restores_both_funding_parts_and_preserves_unrelated_debt(): void
    {
        [$c,$s,$a] = $this->context();
        $this->payment($s, $c, 1000, 'prepaid');
        $sale = $this->debt($s, $c, 1400, 'mixed-sale');
        $this->payment($s, $c, 400, 'settle-remainder');
        $this->debt($s, $c, 600, 'unrelated');
        $s->reverse($sale, 'Correct the fully settled sale.');
        $this->assertSame('600.00', $c->fresh()->current_debt);
        $this->assertSame('1400.00', $c->fresh()->current_credit);
        $this->assertTrue($a->reconciliation(false)['all_reconciled']);
    }

    public function test_consumed_advance_cannot_be_reversed_until_downstream_application_is_reversed(): void
    {
        [$c,$s] = $this->context();
        $payment = $this->payment($s, $c, 1000, 'advance');
        $this->debt($s, $c, 1400, 'advance-sale');
        $count = JournalEntry::count();
        try {
            $s->reverse($payment, 'Do not erase consumed funding.');
            $this->fail('Consumed advance reversed.');
        } catch (ValidationException) {
        }
        $this->assertSame($count, JournalEntry::count());
        $this->assertSame('400.00', $c->fresh()->current_debt);
        $this->assertSame('0.00', $c->fresh()->current_credit);
    }

    public function test_cash_payment_reversal_preserves_ar_cash_and_journal_lineage(): void
    {
        [$c,$s,$a] = $this->context();
        $this->debt($s, $c, 1000, 'sale');
        $payment = $this->payment($s, $c, 1000, 'paid-cash');
        $s->reverse($payment, 'Customer payment was recorded incorrectly.');
        $r = $a->reconciliation(false);
        $this->assertSame('1000.00', $c->fresh()->current_debt);
        $this->assertSame('0.00', $r['rows']['cash_bank']['operational_amount']);
        $this->assertSame('0.00', $r['rows']['cash_bank']['gl_amount']);
        $this->assertTrue($r['all_reconciled']);
        $original = JournalEntry::where('source_type', 'customer_debt_transaction')->where('source_id', $payment->id)->firstOrFail();
        $this->assertSame('reversed', $original->status);
        $this->assertNotNull($original->reversal);
    }
}
