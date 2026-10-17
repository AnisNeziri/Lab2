<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSettlementAllocation;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CustomerDebtService;
use App\Services\ReceivablePositionQueries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DC2CustomerAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        app(AccountingService::class)->initialize();
        $c = Customer::create($this->tenantAttributes(['name' => 'Allocation customer', 'current_debt' => 0, 'current_credit' => 0]));
        $s = app(CustomerDebtService::class);
        $debts = [];
        foreach ([2000, 2500, 1500] as $i => $amount) {
            $debts[] = $s->addDebt($c, ['amount' => $amount, 'transaction_date' => '2026-08-31', 'due_date' => '2026-09-0'.($i + 1), 'reference_number' => 'INV-'.($i + 1), 'idempotency_key' => 'debt-'.$i]);
        }
        $payment = $s->recordPayment($c, ['amount' => 5000, 'transaction_date' => '2026-09-10', 'payment_method' => 'cash', 'idempotency_key' => 'funds-5000']);

        return [$c, $s, $debts, $payment];
    }

    private function selection($debts): array
    {
        return ['idempotency_key' => 'explicit-5000', 'allocation_date' => '2026-09-11', 'reason' => 'Accountant reviewed customer remittance.', 'allocations' => [
            ['debt_transaction_id' => $debts[0]->id, 'amount' => '2000.00'], ['debt_transaction_id' => $debts[1]->id, 'amount' => '2500.00'], ['debt_transaction_id' => $debts[2]->id, 'amount' => '500.00'],
        ]];
    }

    public function test_five_thousand_can_be_split_across_three_obligations_without_new_financial_effects(): void
    {
        [$c,,$d,$p] = $this->fixture();
        $count = JournalEntry::count();
        $before = $c->fresh()->only(['current_debt', 'current_credit']);
        $input = $this->selection($d);
        $ids = $this->postJson('/api/accounting/ar/payments/'.$p->id.'/allocate', $input)->assertOk()->json();
        $this->assertCount(3, $ids);
        $this->postJson('/api/accounting/ar/payments/'.$p->id.'/allocate', $input)->assertOk();
        $this->assertSame(3, CustomerSettlementAllocation::count());
        $this->assertSame($count, JournalEntry::count());
        $this->assertSame($before, $c->fresh()->only(['current_debt', 'current_credit']));
        $open = app(ReceivablePositionQueries::class)->rows('2026-09-11', [$c->id])->get()->keyBy('id');
        $this->assertEquals(0, $open[$d[0]->id]->outstanding_amount);
        $this->assertEquals(0, $open[$d[1]->id]->outstanding_amount);
        $this->assertEquals(1000, $open[$d[2]->id]->outstanding_amount);
        $input['allocations'][0]['amount'] = '1999.00';
        $this->postJson('/api/accounting/ar/payments/'.$p->id.'/allocate', $input)->assertConflict();
        $this->assertTrue(app(AccountingService::class)->reconciliation(false)['all_reconciled']);
    }

    public function test_release_reallocate_preserves_as_of_attribution_and_audit(): void
    {
        [$c,,$d,$p] = $this->fixture();
        $old = $this->postJson('/api/accounting/ar/payments/'.$p->id.'/allocate', $this->selection($d))->assertOk()->json();
        $count = JournalEntry::count();
        $this->postJson('/api/accounting/ar/allocations/'.$old[0]['id'].'/release', ['idempotency_key' => 'release-A', 'allocation_date' => '2026-09-12', 'reason' => 'Customer revised the remittance.'])->assertOk();
        $this->postJson('/api/accounting/ar/payments/'.$p->id.'/allocate', ['idempotency_key' => 'reallocate-A-C', 'allocation_date' => '2026-09-12', 'reason' => 'Allocate according to revised remittance.', 'allocations' => [
            ['debt_transaction_id' => $d[0]->id, 'amount' => 1000], ['debt_transaction_id' => $d[2]->id, 'amount' => 1000],
        ]])->assertOk();
        $before = app(ReceivablePositionQueries::class)->rows('2026-09-11', [$c->id])->get()->keyBy('id');
        $after = app(ReceivablePositionQueries::class)->rows('2026-09-12', [$c->id])->get()->keyBy('id');
        $this->assertEquals(1000, $before[$d[2]->id]->outstanding_amount);
        $this->assertEquals(0, $after[$d[2]->id]->outstanding_amount);
        $this->assertEquals(1000, $after[$d[0]->id]->outstanding_amount);
        $this->assertSame('1000.00', $c->fresh()->current_debt);
        $this->assertSame($count, JournalEntry::count());
        $this->assertSame(5, CustomerSettlementAllocation::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.payment.unallocated', 'entity_id' => $c->id]);
    }

    public function test_overallocation_and_foreign_customer_target_roll_back_all_links(): void
    {
        [$c,$s,$d,$p] = $this->fixture();
        $input = $this->selection($d);
        $input['allocations'][2]['amount'] = 1500;
        $this->postJson('/api/accounting/ar/payments/'.$p->id.'/allocate', $input)->assertUnprocessable();
        $this->assertSame(0, CustomerSettlementAllocation::count());
        $other = Customer::create($this->tenantAttributes(['name' => 'Other customer', 'current_debt' => 0, 'current_credit' => 0]));
        $foreign = $s->addDebt($other, ['amount' => 10, 'transaction_date' => '2026-08-31', 'idempotency_key' => 'other-debt']);
        $this->postJson('/api/accounting/ar/payments/'.$p->id.'/allocate', ['idempotency_key' => 'foreign-target', 'allocation_date' => '2026-09-11', 'reason' => 'Wrong customer must be refused.', 'allocations' => [['debt_transaction_id' => $foreign->id, 'amount' => 10]]])->assertNotFound();
        $this->assertSame(0, CustomerSettlementAllocation::count());
        $this->assertSame('1000.00', $c->fresh()->current_debt);
    }

    public function test_source_reversal_requires_explicit_allocations_to_be_released_first(): void
    {
        [$c,$s,$d,$p] = $this->fixture();
        $rows = $this->postJson('/api/accounting/ar/payments/'.$p->id.'/allocate', $this->selection($d))->assertOk()->json();
        try {
            $s->reverse($p, 'Reverse with active allocations.');
            $this->fail('Allocated payment reversed.');
        } catch (ValidationException) {
        }
        foreach ($rows as $row) {
            $this->postJson('/api/accounting/ar/allocations/'.$row['id'].'/release', ['idempotency_key' => 'release-'.$row['id'], 'allocation_date' => '2026-09-12', 'reason' => 'Release before payment reversal.'])->assertOk();
        }
        $s->reverse($p, 'Wrong payment was recorded.');
        $this->assertSame('6000.00', $c->fresh()->current_debt);
        $this->assertTrue(app(AccountingService::class)->reconciliation(false)['all_reconciled']);
        $this->assertSame(3, CustomerSettlementAllocation::count());
    }
}
