<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerDebtLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_debts_and_payments_keep_correct_history(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Anisi']));

        $this->postJson("/api/customers/{$customer->id}/debts", ['amount' => 300, 'transaction_date' => '2026-07-01'])->assertCreated()->assertJsonPath('balance_after', '300.00');
        $this->postJson("/api/customers/{$customer->id}/debts", ['amount' => 1000, 'transaction_date' => '2026-07-02'])->assertCreated()->assertJsonPath('balance_after', '1300.00');
        $this->postJson("/api/customers/{$customer->id}/payments", ['amount' => 700, 'transaction_date' => '2026-07-03', 'payment_method' => 'cash'])->assertCreated()->assertJsonPath('balance_after', '600.00');
        $this->postJson("/api/customers/{$customer->id}/payments", ['amount' => 600, 'transaction_date' => '2026-07-04', 'payment_method' => 'bank_transfer'])->assertCreated()->assertJsonPath('balance_after', '0.00');

        $this->assertSame('0.00', $customer->fresh()->current_debt);
        $this->assertCount(4, $customer->debtTransactions()->get());
        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('ledger_summary.total_added', 1300)
            ->assertJsonPath('ledger_summary.total_paid', 1300)
            ->assertJsonCount(4, 'debt_transactions');
    }

    public function test_overpayment_and_cross_company_access_are_rejected(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Client']));
        $this->postJson("/api/customers/{$customer->id}/debts", ['amount' => 100, 'transaction_date' => '2026-07-01'])->assertCreated();
        $this->postJson("/api/customers/{$customer->id}/payments", ['amount' => 101, 'transaction_date' => '2026-07-02', 'payment_method' => 'cash'])->assertUnprocessable();

        $foreign = Customer::withoutGlobalScopes()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Foreign']);
        $this->getJson("/api/customers/{$foreign->id}")->assertNotFound();
    }

    public function test_idempotency_key_prevents_duplicate_debt(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Client']));
        $payload = ['amount' => 100, 'transaction_date' => '2026-07-01', 'idempotency_key' => 'same-request'];
        $this->postJson("/api/customers/{$customer->id}/debts", $payload)->assertCreated();
        $this->postJson("/api/customers/{$customer->id}/debts", $payload)->assertCreated();
        $this->assertSame('100.00', $customer->fresh()->current_debt);
        $this->assertCount(1, $customer->debtTransactions()->get());
    }

    public function test_zero_and_negative_amounts_are_rejected(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Client']));

        $this->postJson("/api/customers/{$customer->id}/debts", ['amount' => 0, 'transaction_date' => '2026-07-01'])->assertUnprocessable();
        $this->postJson("/api/customers/{$customer->id}/debts", ['amount' => -10, 'transaction_date' => '2026-07-01'])->assertUnprocessable();
        $this->assertSame('0.00', $customer->fresh()->current_debt);
    }

    public function test_authorized_manager_can_reverse_without_deleting_original_history(): void
    {
        $this->actingAsApiUser('manager');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Client']));
        $created = $this->postJson("/api/customers/{$customer->id}/debts", [
            'amount' => 300,
            'transaction_date' => '2026-07-01',
        ])->assertCreated();

        $this->postJson("/api/debt-transactions/{$created->json('id')}/reverse", [
            'reason' => 'The sale was entered by mistake.',
        ])->assertCreated()->assertJsonPath('type', 'cancellation')->assertJsonPath('balance_after', '0.00');

        $this->assertSame('0.00', $customer->fresh()->current_debt);
        $this->assertDatabaseHas('customer_debt_transactions', ['id' => $created->json('id'), 'type' => 'debt_added']);
        $this->assertDatabaseHas('customer_debt_transactions', ['reversed_transaction_id' => $created->json('id'), 'type' => 'cancellation']);
        $this->assertSame(2, CustomerDebtTransaction::count());
    }

    public function test_debt_can_be_corrected_after_it_was_fully_paid(): void
    {
        $this->actingAsApiUser('manager');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Paid Client']));
        $debt = $this->postJson("/api/customers/{$customer->id}/debts", [
            'amount' => 300,
            'transaction_date' => '2026-07-01',
        ])->assertCreated();
        $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount' => 300,
            'transaction_date' => '2026-07-02',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->postJson("/api/debt-transactions/{$debt->json('id')}/reverse", [
            'reason' => 'The original debt was entered incorrectly.',
        ])->assertCreated()
            ->assertJsonPath('type', 'cancellation')
            ->assertJsonPath('balance_after', '-300.00');

        $this->assertSame('-300.00', $customer->fresh()->current_debt);
    }

    public function test_transaction_can_be_fully_corrected_more_than_once_and_later_balances_are_rebuilt(): void
    {
        $this->actingAsApiUser('manager');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Editable Client']));
        $first = $this->postJson("/api/customers/{$customer->id}/debts", [
            'amount' => 100,
            'transaction_date' => '2026-07-01',
            'note' => 'Original note',
        ])->assertCreated();
        $second = $this->postJson("/api/customers/{$customer->id}/debts", [
            'amount' => 50,
            'transaction_date' => '2026-07-02',
        ])->assertCreated();

        $this->putJson("/api/debt-transactions/{$first->json('id')}", [
            'amount' => 200,
            'transaction_date' => '2026-07-01',
            'due_date' => '2026-07-20',
            'reference_number' => 'REF-1',
            'note' => 'Corrected note',
            'reason' => 'The original amount and details were wrong.',
        ])->assertOk()
            ->assertJsonPath('amount', '200.00')
            ->assertJsonPath('note', 'Corrected note');

        $this->assertSame('250.00', $customer->fresh()->current_debt);
        $this->assertSame('250.00', CustomerDebtTransaction::find($second->json('id'))->balance_after);

        $this->putJson("/api/debt-transactions/{$first->json('id')}", [
            'amount' => 175,
            'transaction_date' => '2026-07-01',
            'due_date' => null,
            'reference_number' => null,
            'note' => 'Corrected again',
            'reason' => 'A second verified correction was required.',
        ])->assertOk()->assertJsonPath('amount', '175.00');

        $corrected = CustomerDebtTransaction::find($first->json('id'));
        $this->assertSame('225.00', $customer->fresh()->current_debt);
        $this->assertCount(2, $corrected->metadata['correction_history']);
    }

    public function test_customer_list_supports_filters_and_pagination(): void
    {
        $this->actingAsApiUser('admin');
        Customer::create($this->tenantAttributes(['name' => 'Anisi Trade', 'current_debt' => 500]));
        Customer::create($this->tenantAttributes(['name' => 'Paid Client', 'current_debt' => 0]));

        $this->getJson('/api/customers?search=Anisi&status=active&min_debt=100&max_debt=600&per_page=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('data.0.name', 'Anisi Trade');
    }

    public function test_paid_and_active_debt_sheets_can_be_deleted(): void
    {
        $this->actingAsApiUser('admin');
        $paid = Customer::create($this->tenantAttributes(['name' => 'Paid']));
        $active = Customer::create($this->tenantAttributes(['name' => 'Active', 'current_debt' => 50]));

        $this->deleteJson("/api/customers/{$active->id}")->assertOk();
        $this->deleteJson("/api/customers/{$paid->id}")->assertOk();

        $this->assertSoftDeleted('customers', ['id' => $paid->id]);
        $this->assertSoftDeleted('customers', ['id' => $active->id]);
    }
}
