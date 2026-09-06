<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCreditSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_limit_is_unrestricted_and_exact_limit_is_allowed(): void
    {
        $this->actingAsApiUser('admin');
        $unlimited = Customer::create($this->tenantAttributes(['name' => 'Unlimited']));
        $this->postJson("/api/customers/{$unlimited->id}/debts", $this->debt('999999.99', 'unlimited'))->assertCreated();

        $limited = Customer::create($this->tenantAttributes(['name' => 'Limited', 'credit_limit' => '100.00']));
        $this->postJson("/api/customers/{$limited->id}/debts", $this->debt('100.00', 'exact'))->assertCreated();
        $this->postJson("/api/customers/{$limited->id}/debts", $this->debt('0.01', 'above'))
            ->assertUnprocessable()
            ->assertJsonPath('credit_control.current_exposure', '100.00')
            ->assertJsonPath('credit_control.projected_exposure', '100.01');
    }

    public function test_advance_reduces_projected_exposure_with_fixed_precision(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes([
            'name' => 'Advance Client', 'credit_limit' => '100.00', 'current_credit' => '50.00',
        ]));

        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('150.00', 'advance-exact'))
            ->assertCreated()->assertJsonPath('balance_after', '100.00')->assertJsonPath('credit_after', '0.00');
        $this->assertSame('100.00', $customer->fresh()->current_debt);
    }

    public function test_payment_terms_supply_due_date_and_explicit_date_wins(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00', 'Europe/Tirane'));
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Terms Client', 'payment_terms_days' => 30]));

        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('10.00', 'terms-default'))
            ->assertCreated()->assertJsonPath('due_date', '2026-10-01T00:00:00.000000Z');
        $explicit = $this->debt('10.00', 'terms-explicit');
        $explicit['due_date'] = '2026-09-15';
        $this->postJson("/api/customers/{$customer->id}/debts", $explicit)
            ->assertCreated()->assertJsonPath('due_date', '2026-09-15T00:00:00.000000Z');
    }

    public function test_aging_allocates_partial_payment_to_oldest_due_first(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00', 'Europe/Tirane'));
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Aging Client']));
        foreach ([
            ['10.00', '2026-09-10', 'current'], ['20.00', '2026-08-22', 'd10'],
            ['30.00', '2026-07-23', 'd40'], ['40.00', '2026-06-23', 'd70'],
            ['50.00', '2026-05-24', 'd100'],
        ] as [$amount, $due, $key]) {
            $payload = $this->debt($amount, $key);
            $payload['transaction_date'] = CarbonImmutable::parse($due)->subDay()->toDateString();
            $payload['due_date'] = $due;
            $this->postJson("/api/customers/{$customer->id}/debts", $payload)->assertCreated();
        }
        $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount' => '25.00', 'transaction_date' => '2026-09-01', 'payment_method' => 'cash', 'idempotency_key' => 'aging-pay',
        ])->assertCreated();

        $this->getJson("/api/customers/{$customer->id}")->assertOk()
            ->assertJsonPath('credit_summary.aging.current', '10.00')
            ->assertJsonPath('credit_summary.aging.1_30', '20.00')
            ->assertJsonPath('credit_summary.aging.31_60', '30.00')
            ->assertJsonPath('credit_summary.aging.61_90', '40.00')
            ->assertJsonPath('credit_summary.aging.90_plus', '25.00')
            ->assertJsonPath('credit_summary.overdue', '115.00')
            ->assertJsonPath('credit_summary.oldest_overdue_date', '2026-05-24');
    }

    public function test_authorized_credit_settings_record_and_release_hold(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Managed Client']));
        $this->putJson("/api/customers/{$customer->id}", [
            'credit_limit' => '250.50', 'payment_terms_days' => 21,
            'credit_status' => 'blocked', 'credit_hold_reason' => 'Repeated late payments.',
        ])->assertOk()->assertJsonPath('credit_limit', '250.50')->assertJsonPath('payment_terms_days', 21);
        $held = $customer->fresh();
        $this->assertNotNull($held->credit_hold_at);
        $this->assertNotNull($held->credit_hold_by);

        $this->putJson("/api/customers/{$customer->id}", ['credit_status' => 'normal', 'credit_hold_reason' => null])->assertOk();
        $this->assertNull($customer->fresh()->credit_hold_at);
    }

    public function test_zero_limit_is_enforced_without_dividing_by_zero(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes([
            'name' => 'Zero Limit Client',
            'credit_limit' => '0.00',
        ]));

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('credit_summary.credit_limit', '0.00')
            ->assertJsonPath('credit_summary.utilization_percent', null);

        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('0.01', 'zero-limit'))
            ->assertUnprocessable()
            ->assertJsonPath('credit_control.reason_code', 'over_limit')
            ->assertJsonPath('credit_control.projected_exposure', '0.01');
    }

    public function test_aging_ignores_reversed_entries_and_allocates_overdue_before_undated_debt(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00', 'Europe/Tirane'));
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes(['name' => 'Reconciled Aging Client']));

        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('30.00', 'undated'))
            ->assertCreated();
        $overduePayload = $this->debt('40.00', 'overdue');
        $overduePayload['transaction_date'] = '2026-07-01';
        $overduePayload['due_date'] = '2026-07-15';
        $overdue = $this->postJson("/api/customers/{$customer->id}/debts", $overduePayload)->assertCreated();

        $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount' => '20.00',
            'transaction_date' => '2026-09-01',
            'payment_method' => 'cash',
            'idempotency_key' => 'aging-order-payment',
        ])->assertCreated();

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('credit_summary.aging.current', '30.00')
            ->assertJsonPath('credit_summary.aging.31_60', '20.00');

        $this->postJson("/api/debt-transactions/{$overdue->json('id')}/reverse", [
            'reason' => 'The overdue entry was recorded in error.',
        ])->assertCreated();

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('credit_summary.aging.current', '10.00')
            ->assertJsonPath('credit_summary.aging.31_60', '0.00')
            ->assertJsonPath('credit_summary.overdue', '0.00');
    }

    public function test_debt_increase_correction_cannot_bypass_credit_control_and_rolls_back_atomically(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes([
            'name' => 'Correction Limit Client',
            'credit_limit' => '100.00',
        ]));
        $original = $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('50.00', 'correction-original'))
            ->assertCreated();
        $this->postJson("/api/customers/{$customer->id}/debts", $this->debt('40.00', 'correction-other'))
            ->assertCreated();

        $unrelatedApproval = $this->postJson("/api/customers/{$customer->id}/credit-overrides", [
            ...$this->debt('20.00', 'unrelated-correction-approval'),
            'reason' => 'Approve a separate intended transaction.',
        ])->assertCreated();
        $manager = User::factory()->create([
            'company_id' => $this->apiCompany->id,
            'role' => 'manager',
            'api_token' => hash('sha256', 'correction-manager'),
        ]);
        $this->withToken('correction-manager')->postJson(
            "/api/approvals/{$unrelatedApproval->json('id')}/decision",
            ['decision' => 'approved'],
        )->assertOk();

        $this->withToken('test-token-admin')->putJson(
            "/api/debt-transactions/{$original->json('id')}",
            [
                'amount' => '70.00',
                'transaction_date' => '2026-09-01',
                'reason' => 'Correct the recorded customer obligation.',
                'approval_request_id' => $unrelatedApproval->json('id'),
            ],
        )->assertUnprocessable()
            ->assertJsonPath('credit_control.reason_code', 'over_limit')
            ->assertJsonPath('credit_control.projected_exposure', '110.00')
            ->assertJsonPath('credit_control.approval_matches_transaction', false);

        $this->assertSame('90.00', $customer->fresh()->current_debt);
        $this->assertDatabaseCount('customer_debt_transactions', 2);
        $this->assertDatabaseMissing('customer_debt_transactions', [
            'reversed_transaction_id' => $original->json('id'),
        ]);
        $this->assertDatabaseHas('approval_requests', [
            'id' => $unrelatedApproval->json('id'),
            'status' => 'approved',
        ]);

        // A correction whose final exposure is exactly the limit is allowed.
        $this->putJson("/api/debt-transactions/{$original->json('id')}", [
            'amount' => '60.00',
            'transaction_date' => '2026-09-01',
            'reason' => 'Correct the amount to the verified value.',
        ])->assertOk();
        $this->assertSame('100.00', $customer->fresh()->current_debt);
    }

    private function debt(string $amount, string $key): array
    {
        return ['amount' => $amount, 'transaction_date' => '2026-09-01', 'idempotency_key' => $key];
    }
}
