<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCreditOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_over_limit_override_is_deduplicated_approved_consumed_and_idempotently_replayed(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes([
            'name' => 'Limit Client',
            'current_debt' => '80.00',
            'credit_limit' => '100.00',
        ]));
        $payload = $this->debtPayload('25.00', 'override-one');

        $this->postJson("/api/customers/{$customer->id}/debts", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('credit_control.reason_code', 'over_limit')
            ->assertJsonPath('credit_control.credit_limit', '100.00')
            ->assertJsonPath('credit_control.current_exposure', '80.00')
            ->assertJsonPath('credit_control.projected_exposure', '105.00');

        $first = $this->postJson("/api/customers/{$customer->id}/credit-overrides", [
            ...$payload,
            'reason' => 'Release this confirmed order.',
            'source' => ['entity_type' => 'customer_debt_entry', 'reference' => 'override-one'],
        ])->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('required_role', null)
            ->assertJsonPath('context.customer_id', $customer->id)
            ->assertJsonPath('context.proposed_amount', '25.00')
            ->assertJsonPath('context.current_exposure', '80.00')
            ->assertJsonPath('context.projected_exposure', '105.00')
            ->assertJsonPath('context.credit_limit', '100.00')
            ->assertJsonPath('context.override_reason', 'Release this confirmed order.')
            ->assertJsonPath('context.source_entity', 'customer_debt_entry')
            ->assertJsonPath('context.source_reference', 'override-one');

        $approvalId = $first->json('id');
        $this->postJson("/api/customers/{$customer->id}/credit-overrides", [
            ...$payload,
            'reason' => 'A duplicate click must reuse the pending request.',
            'source' => ['entity_type' => 'customer_debt_entry', 'reference' => 'override-one'],
        ])->assertOk()
            ->assertJsonPath('id', $approvalId)
            ->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('approval_requests', 1);

        $this->postJson("/api/customers/{$customer->id}/debts", [
            ...$payload,
            'approval_request_id' => $approvalId,
        ])->assertUnprocessable()
            ->assertJsonPath('credit_control.approval_status', 'pending');

        $this->manager('credit-manager');
        $this->withToken('credit-manager')
            ->postJson("/api/approvals/{$approvalId}/decision", [
                'decision' => 'approved',
                'comment' => 'Commercial exception approved.',
            ])->assertOk()->assertJsonPath('status', 'approved');

        $created = $this->withToken('test-token-admin')
            ->postJson("/api/customers/{$customer->id}/debts", [
                ...$payload,
                'approval_request_id' => $approvalId,
            ])->assertCreated()
            ->assertJsonPath('balance_after', '105.00')
            ->assertJsonPath('metadata.credit_override_approval_request_id', $approvalId);

        $this->assertDatabaseHas('approval_requests', ['id' => $approvalId, 'status' => 'consumed']);
        $this->assertDatabaseHas('approval_decisions', ['approval_request_id' => $approvalId, 'decision' => 'approved']);
        $this->assertDatabaseHas('approval_decisions', ['approval_request_id' => $approvalId, 'decision' => 'consumed']);

        // An exact HTTP retry returns the original debt without consuming or
        // creating anything again.
        $this->postJson("/api/customers/{$customer->id}/debts", [
            ...$payload,
            'approval_request_id' => $approvalId,
        ])->assertCreated()->assertJsonPath('id', $created->json('id'));
        $this->assertDatabaseCount('customer_debt_transactions', 1);

        $this->postJson("/api/customers/{$customer->id}/debts", [
            ...$this->debtPayload('25.00', 'override-two'),
            'approval_request_id' => $approvalId,
        ])->assertUnprocessable()
            ->assertJsonPath('credit_control.approval_status', 'consumed');
    }

    public function test_rejected_and_materially_changed_transactions_remain_blocked_and_need_new_approval(): void
    {
        $this->actingAsApiUser('admin');
        ApprovalRule::create($this->tenantAttributes([
            'rule_type' => 'customer_credit_override',
            'required_role' => 'manager',
            'separation_of_duties' => true,
            'is_active' => true,
        ]));
        $firstCustomer = Customer::create($this->tenantAttributes([
            'name' => 'First Client',
            'current_debt' => '90.00',
            'credit_limit' => '100.00',
        ]));
        $secondCustomer = Customer::create($this->tenantAttributes([
            'name' => 'Second Client',
            'credit_status' => 'blocked',
        ]));
        $payload = $this->debtPayload('20.00', 'rejected-intent');
        $approvalId = $this->postJson("/api/customers/{$firstCustomer->id}/credit-overrides", [
            ...$payload,
            'reason' => 'Customer requests an exception.',
        ])->assertCreated()->json('id');

        $this->manager('rejecting-manager');
        $this->withToken('rejecting-manager')
            ->postJson("/api/approvals/{$approvalId}/decision", ['decision' => 'rejected', 'comment' => 'Risk too high.'])
            ->assertOk()->assertJsonPath('status', 'rejected');

        $this->withToken('test-token-admin')
            ->postJson("/api/customers/{$firstCustomer->id}/debts", [
                ...$payload,
                'approval_request_id' => $approvalId,
            ])->assertUnprocessable()
            ->assertJsonPath('credit_control.approval_status', 'rejected');

        // A rejected decision is immutable; a later attempt creates a new
        // approval record rather than changing the rejected history.
        $replacement = $this->postJson("/api/customers/{$firstCustomer->id}/credit-overrides", [
            ...$payload,
            'reason' => 'New evidence supports reconsideration.',
        ])->assertCreated();
        $this->assertNotSame($approvalId, $replacement->json('id'));
        $this->assertDatabaseHas('approval_requests', ['id' => $approvalId, 'status' => 'rejected']);

        $replacementId = $replacement->json('id');
        $this->withToken('rejecting-manager')
            ->postJson("/api/approvals/{$replacementId}/decision", ['decision' => 'approved'])
            ->assertOk();

        $this->withToken('test-token-admin')
            ->postJson("/api/customers/{$firstCustomer->id}/debts", [
                ...$this->debtPayload('21.00', 'changed-amount'),
                'approval_request_id' => $replacementId,
            ])->assertUnprocessable()
            ->assertJsonPath('credit_control.approval_status', 'approved')
            ->assertJsonPath('credit_control.approval_matches_transaction', false);

        $this->postJson("/api/customers/{$secondCustomer->id}/debts", [
            ...$payload,
            'approval_request_id' => $replacementId,
        ])->assertUnprocessable()
            ->assertJsonPath('credit_control.reason_code', 'customer_blocked')
            ->assertJsonPath('credit_control.approval_request_id', null);

        $changed = $this->postJson("/api/customers/{$firstCustomer->id}/credit-overrides", [
            ...$this->debtPayload('21.00', 'changed-amount'),
            'reason' => 'Approve the revised amount.',
        ])->assertCreated();
        $this->assertNotSame($replacementId, $changed->json('id'));
    }

    public function test_existing_self_approval_permissions_and_tenant_isolation_apply_to_credit_overrides(): void
    {
        $this->actingAsApiUser('admin');
        ApprovalRule::create($this->tenantAttributes([
            'rule_type' => 'customer_credit_override',
            'required_role' => 'admin',
            'separation_of_duties' => true,
            'is_active' => true,
        ]));
        $customer = Customer::create($this->tenantAttributes([
            'name' => 'Blocked Client',
            'credit_status' => 'blocked',
        ]));
        $approvalId = $this->postJson("/api/customers/{$customer->id}/credit-overrides", [
            ...$this->debtPayload('10.00', 'security-intent'),
            'reason' => 'Request a blocked-customer exception.',
        ])->assertCreated()->json('id');

        $this->postJson("/api/approvals/{$approvalId}/decision", ['decision' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approval');

        User::factory()->create([
            'company_id' => $this->apiCompany->id,
            'role' => 'staff',
            'api_token' => hash('sha256', 'unauthorized-staff'),
        ]);
        $this->withToken('unauthorized-staff')
            ->postJson("/api/approvals/{$approvalId}/decision", ['decision' => 'approved'])
            ->assertForbidden();

        $foreignCompany = Company::factory()->create();
        User::factory()->create([
            'company_id' => $foreignCompany->id,
            'role' => 'admin',
            'api_token' => hash('sha256', 'foreign-admin'),
        ]);
        $this->withToken('foreign-admin')
            ->postJson("/api/approvals/{$approvalId}/decision", ['decision' => 'approved'])
            ->assertNotFound();
        $this->withToken('foreign-admin')
            ->getJson("/api/customers/{$customer->id}/credit-overrides/{$approvalId}")
            ->assertNotFound();

        $this->assertSame('pending', ApprovalRequest::withoutGlobalScopes()->findOrFail($approvalId)->status);
    }

    public function test_changed_payment_terms_invalidate_an_approved_transaction_intent(): void
    {
        $this->actingAsApiUser('admin');
        $customer = Customer::create($this->tenantAttributes([
            'name' => 'Terms Override Client',
            'current_debt' => '90.00',
            'credit_limit' => '100.00',
            'payment_terms_days' => 30,
        ]));
        $payload = $this->debtPayload('20.00', 'terms-override');
        $approvalId = $this->postJson("/api/customers/{$customer->id}/credit-overrides", [
            ...$payload,
            'reason' => 'Approve this obligation using current payment terms.',
        ])->assertCreated()
            ->assertJsonPath('context.due_date', '2026-10-01')
            ->json('id');

        $this->manager('terms-manager');
        $this->withToken('terms-manager')->postJson("/api/approvals/{$approvalId}/decision", [
            'decision' => 'approved',
        ])->assertOk();

        $customer->update(['payment_terms_days' => 45]);
        $this->withToken('test-token-admin')->postJson("/api/customers/{$customer->id}/debts", [
            ...$payload,
            'approval_request_id' => $approvalId,
        ])->assertUnprocessable()
            ->assertJsonPath('credit_control.approval_status', 'approved')
            ->assertJsonPath('credit_control.approval_matches_transaction', false);

        $this->assertSame('90.00', $customer->fresh()->current_debt);
        $this->assertDatabaseHas('approval_requests', ['id' => $approvalId, 'status' => 'approved']);
    }

    private function debtPayload(string $amount, string $key): array
    {
        return [
            'amount' => $amount,
            'transaction_date' => '2026-09-01',
            'idempotency_key' => $key,
        ];
    }

    private function manager(string $token): User
    {
        return User::factory()->create([
            'company_id' => $this->apiCompany->id,
            'role' => 'manager',
            'api_token' => hash('sha256', $token),
        ]);
    }
}
