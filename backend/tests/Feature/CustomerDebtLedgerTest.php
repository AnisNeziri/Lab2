<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerDebtLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_debts_and_partial_payments_keep_exact_history(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Anisi']));

        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('300.00', '2026-07-01', 'debt-1'))
            ->assertCreated()->assertJsonPath('balance_after', '300.00');
        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('1000.00', '2026-07-02', 'debt-2'))
            ->assertCreated()->assertJsonPath('balance_after', '1300.00');
        $this->postJson("/api/customers/{$customer->id}/payments", $this->payment('700.00', '2026-07-03', 'payment-1'))
            ->assertCreated()->assertJsonPath('balance_after', '600.00');
        $this->postJson("/api/customers/{$customer->id}/payments", $this->payment('600.00', '2026-07-04', 'payment-2'))
            ->assertCreated()->assertJsonPath('balance_after', '0.00');

        $this->assertSame('0.00', $customer->fresh()->current_debt);
        $this->assertSame('0.00', $customer->fresh()->current_credit);
        $this->assertCount(4, $customer->debtTransactions()->get());
        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('ledger_summary.total_added', 1300)
            ->assertJsonPath('ledger_summary.total_paid', 1300)
            ->assertJsonCount(4, 'debt_transactions');
    }

    public function test_overpayment_becomes_credit_and_future_debt_uses_it_first(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Client']));
        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('100.00', '2026-07-01', 'credit-debt'))
            ->assertCreated();
        $this->postJson("/api/customers/{$customer->id}/payments", $this->payment('150.00', '2026-07-02', 'credit-payment'))
            ->assertCreated()
            ->assertJsonPath('balance_after', '0.00')
            ->assertJsonPath('credit_after', '50.00')
            ->assertJsonPath('metadata.credit_created', '50.00');

        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('30.00', '2026-07-03', 'credit-use'))
            ->assertCreated()
            ->assertJsonPath('balance_after', '0.00')
            ->assertJsonPath('credit_after', '20.00')
            ->assertJsonPath('metadata.credit_applied', '30.00');
        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('50.00', '2026-07-04', 'credit-use-2'))
            ->assertCreated()
            ->assertJsonPath('balance_after', '30.00')
            ->assertJsonPath('credit_after', '0.00');

        $fresh = $customer->fresh();
        $this->assertSame('30.00', $fresh->current_debt);
        $this->assertSame('0.00', $fresh->current_credit);

        $foreignId = DB::table('customers')->insertGetId([
            'company_id' => Company::factory()->create()->id,
            'name' => 'Foreign',
            'current_debt' => '0.00',
            'current_credit' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson("/api/customers/{$foreignId}")->assertNotFound();
        $this->postJson("/api/customers/{$foreignId}/debts", $this->debt('1.00', '2026-07-05', 'foreign'))
            ->assertNotFound();
    }

    public function test_idempotency_exact_replay_is_safe_and_mismatched_reuse_is_rejected(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Client']));
        $payload = $this->debt('100.00', '2026-07-01', 'same-request') + [
            'due_date' => '2026-07-20',
            'note' => 'Original request',
        ];

        $first = $this->postJson("/api/customers/{$customer->id}/debts", $payload)->assertCreated();
        $this->postJson("/api/customers/{$customer->id}/debts", $payload)
            ->assertCreated()->assertJsonPath('id', $first->json('id'));

        $mismatch = $payload;
        $mismatch['due_date'] = '2026-07-21';
        $this->postJson("/api/customers/{$customer->id}/debts", $mismatch)
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertSame('100.00', $customer->fresh()->current_debt);
        $this->assertDatabaseCount('customer_debt_transactions', 1);
    }

    public function test_decimal_payments_do_not_leave_binary_float_residue(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Precision Client']));

        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('0.30', '2026-07-01', 'precision-debt'))->assertCreated();
        $this->postJson("/api/customers/{$customer->id}/payments", $this->payment('0.10', '2026-07-02', 'precision-pay-1'))->assertCreated();
        $this->postJson("/api/customers/{$customer->id}/payments", $this->payment('0.20', '2026-07-03', 'precision-pay-2'))->assertCreated();

        $this->assertSame('0.00', $customer->fresh()->current_debt);
        $this->assertSame('0.00', $customer->fresh()->current_credit);
    }

    public function test_reversal_preserves_original_history_and_turns_unapplied_payment_into_credit(): void
    {
        $this->actingAsApiUser('manager');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Paid Client']));
        $debt = $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('300.00', '2026-07-01', 'paid-debt'))
            ->assertCreated();
        $this->postJson("/api/customers/{$customer->id}/payments", $this->payment('300.00', '2026-07-02', 'paid-payment'))
            ->assertCreated();

        $this->postJson("/api/debt-transactions/{$debt->json('id')}/reverse", [
            'reason' => 'The original debt was entered incorrectly.',
        ])->assertCreated()
            ->assertJsonPath('type', 'cancellation')
            ->assertJsonPath('balance_after', '0.00')
            ->assertJsonPath('credit_after', '300.00');

        $this->assertSame('0.00', $customer->fresh()->current_debt);
        $this->assertSame('300.00', $customer->fresh()->current_credit);
        $this->assertDatabaseHas('customer_debt_transactions', [
            'id' => $debt->json('id'),
            'type' => 'debt_added',
            'amount' => '300.00',
        ]);
        $this->assertDatabaseHas('customer_debt_transactions', [
            'reversed_transaction_id' => $debt->json('id'),
            'type' => 'cancellation',
        ]);
    }

    public function test_repeated_corrections_append_reversals_without_mutating_history(): void
    {
        $this->actingAsApiUser('manager');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Correctable Client']));
        $original = $this->postJson("/api/customers/{$customer->id}/debts", [
            ...$this->debt('100.00', '2026-07-01', 'original-debt'),
            'note' => 'Original note',
        ])->assertCreated();
        $later = $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('50.00', '2026-07-02', 'later-debt'))
            ->assertCreated();

        $firstCorrection = $this->putJson("/api/debt-transactions/{$original->json('id')}", [
            'amount' => '200.00',
            'transaction_date' => '2026-07-01',
            'due_date' => '2026-07-20',
            'reference_number' => 'REF-1',
            'note' => 'Corrected note',
            'reason' => 'The original amount and details were wrong.',
        ])->assertOk()
            ->assertJsonPath('amount', '200.00')
            ->assertJsonPath('note', 'Corrected note');

        $this->assertNotSame($original->json('id'), $firstCorrection->json('id'));
        $this->assertSame('100.00', CustomerDebtTransaction::find($original->json('id'))->amount);
        $this->assertSame('150.00', CustomerDebtTransaction::find($later->json('id'))->balance_after);
        $this->assertSame('250.00', $customer->fresh()->current_debt);

        $secondCorrection = $this->putJson("/api/debt-transactions/{$firstCorrection->json('id')}", [
            'amount' => '175.00',
            'transaction_date' => '2026-07-01',
            'due_date' => null,
            'reference_number' => null,
            'note' => 'Corrected again',
            'reason' => 'A second verified correction was required.',
        ])->assertOk()->assertJsonPath('amount', '175.00');

        $this->assertNotSame($firstCorrection->json('id'), $secondCorrection->json('id'));
        $this->assertSame('200.00', CustomerDebtTransaction::find($firstCorrection->json('id'))->amount);
        $this->assertSame('225.00', $customer->fresh()->current_debt);
        $this->assertDatabaseCount('customer_debt_transactions', 6);
        $this->assertSame(2, CustomerDebtTransaction::query()->whereNotNull('reversed_transaction_id')->count());
    }

    public function test_zero_and_negative_amounts_are_rejected(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Client']));

        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('0.00', '2026-07-01', 'zero'))->assertUnprocessable();
        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('-10.00', '2026-07-01', 'negative'))->assertUnprocessable();
        $this->assertSame('0.00', $customer->fresh()->current_debt);
    }

    public function test_customer_list_supports_filters_and_pagination(): void
    {
        $this->actingAsApiUser('admin');
        Customer::create($this->tenantAttributes(['name' => 'Anisi Trade', 'current_debt' => '500.00']));
        Customer::create($this->tenantAttributes(['name' => 'Paid Client', 'current_debt' => '0.00']));

        $this->getJson('/api/customers?search=Anisi&status=active&min_debt=100&max_debt=600&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('data.0.name', 'Anisi Trade');
    }

    public function test_only_settled_sheets_without_credit_can_be_soft_deleted(): void
    {
        $this->actingAsApiUser('admin');
        $settled = Customer::create($this->tenantAttributes(['name' => 'Settled']));
        $active = Customer::create($this->tenantAttributes(['name' => 'Active', 'current_debt' => '50.00']));
        $credit = Customer::create($this->tenantAttributes(['name' => 'Credit', 'current_credit' => '20.00']));

        $this->deleteJson("/api/customers/{$active->id}")->assertUnprocessable();
        $this->deleteJson("/api/customers/{$credit->id}")->assertUnprocessable();
        $this->deleteJson("/api/customers/{$settled->id}")->assertOk();

        $this->assertNotSoftDeleted('customers', ['id' => $active->id]);
        $this->assertNotSoftDeleted('customers', ['id' => $credit->id]);
        $this->assertSoftDeleted('customers', ['id' => $settled->id]);
    }

    private function debt(string $amount, string $date, string $key): array
    {
        return ['amount' => $amount, 'transaction_date' => $date, 'idempotency_key' => $key];
    }

    private function payment(string $amount, string $date, string $key): array
    {
        return [
            'amount' => $amount,
            'transaction_date' => $date,
            'payment_method' => 'cash',
            'idempotency_key' => $key,
        ];
    }
}
