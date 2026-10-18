<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class DC2ManualJournalTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $role = 'admin'): array
    {
        $this->actingAsApiUser($role);
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        $s = app(AccountingService::class);
        $s->initialize();

        return [$s, collect($s->accounts())->keyBy('code')];
    }

    private function payload($accounts): array
    {
        return ['posting_date' => '2026-09-30', 'reference_number' => 'DC2-MANUAL', 'description' => 'Reviewed owner contribution', 'lines' => [
            ['accounting_account_id' => $accounts['1000']->id, 'debit' => '90.00'],
            ['accounting_account_id' => $accounts['3000']->id, 'credit' => '90.00'],
        ]];
    }

    private function ack(): array
    {
        return ['acknowledge_controls' => true, 'control_reason' => 'Reviewed contribution; no operational receivable or stock is changed.'];
    }

    public function test_control_posting_needs_explicit_acknowledgement_and_edit_invalidates_it(): void
    {
        [, $a] = $this->context();
        $payload = $this->payload($a);
        $id = $this->postJson('/api/accounting/journals', $payload + $this->ack())->assertCreated()->json('id');
        $this->putJson('/api/accounting/journals/'.$id, [...$payload, 'revision' => 1, 'description' => 'Changed reviewed contribution'])->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('control_acknowledgement', null);
        $this->putJson('/api/accounting/journals/'.$id, [...$payload, 'revision' => 1])->assertConflict();
        $this->postJson('/api/accounting/journals/'.$id.'/post')->assertUnprocessable();
        $this->postJson('/api/accounting/journals/'.$id.'/post', $this->ack() + ['revision' => 2])->assertOk()->assertJsonPath('status', 'posted');
        $this->putJson('/api/accounting/journals/'.$id, [...$payload, 'revision' => 2])->assertUnprocessable();
        $this->getJson('/api/accounting/journals/'.$id)->assertOk()->assertJsonPath('totals.difference', '0.00')->assertJsonPath('source_label', 'Manual Journal');
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounting.manual_journal.saved', 'entity_id' => $id]);
    }

    public function test_generic_manager_does_not_receive_control_adjustment_permission(): void
    {
        [, $a] = $this->context('manager');
        $this->postJson('/api/accounting/journals', $this->payload($a) + $this->ack())->assertForbidden();
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_soft_close_requires_explicit_adjustment_and_final_close_still_blocks(): void
    {
        [$s, $a] = $this->context();
        $period = $s->savePeriod(['name' => 'September', 'starts_at' => '2026-09-01', 'ends_at' => '2026-09-30']);
        $s->setPeriodStatus($period, 'soft_closed', 'Accountant review in progress.');
        $id = $this->postJson('/api/accounting/journals', $this->payload($a))->assertCreated()->json('id');
        $this->postJson('/api/accounting/journals/'.$id.'/post', $this->ack())->assertUnprocessable();
        $this->postJson('/api/accounting/journals/'.$id.'/post', $this->ack() + ['soft_close_adjustment' => true])->assertOk();
        // A separate operational close journey will exercise readiness; this
        // guard fixture isolates the final-close date boundary itself.
        AccountingPeriod::whereKey($period->id)->update(['status' => 'closed']);
        $this->postJson('/api/accounting/journals', $this->payload($a))->assertUnprocessable();
    }

    public function test_foreign_manual_journal_requires_a_historical_rate_snapshot(): void
    {
        [, $a] = $this->context();
        $payload = $this->payload($a);
        $payload['currency'] = 'USD';
        $payload['exchange_rate'] = '.9';
        $payload['lines'][0]['foreign_debit'] = '100.00';
        $payload['lines'][1]['foreign_credit'] = '100.00';
        $this->postJson('/api/accounting/journals', $payload)->assertUnprocessable();
        $id = $this->postJson('/api/accounting/journals', $payload + ['exchange_rate_date' => '2026-09-30', 'exchange_rate_source' => 'Authorized manual historical rate'])->assertCreated()->json('id');
        $this->postJson('/api/accounting/journals/'.$id.'/post', $this->ack())->assertOk();
        $this->getJson('/api/accounting/journals/'.$id)->assertOk()->assertJsonPath('exchange_rate', '0.90000000')->assertJsonPath('totals.debit', '90.00');
        $this->assertSame('100.00', JournalEntry::findOrFail($id)->lines()->first()->foreign_debit);
    }

    public function test_source_owned_journal_cannot_be_reversed_from_manual_screen(): void
    {
        [$s, $a] = $this->context();
        $j = $s->createAndPost($this->payload($a) + ['source_module' => 'supplier_accounting', 'source_key' => 'owned-source']);
        $this->postJson('/api/accounting/journals/'.$j->id.'/reverse', ['posting_date' => '2026-10-01', 'reason' => 'Attempt a subledger bypass.'])->assertUnprocessable();
        $this->assertSame('posted', $j->fresh()->status);
    }

    public function test_correct_and_repost_keeps_original_and_creates_an_unposted_linked_draft(): void
    {
        [, $a] = $this->context();
        $id = $this->postJson('/api/accounting/journals', $this->payload($a))->assertCreated()->json('id');
        $this->postJson('/api/accounting/journals/'.$id.'/post', $this->ack())->assertOk();
        $result = $this->postJson('/api/accounting/journals/'.$id.'/correct', ['posting_date' => '2026-10-01', 'reason' => 'Correct the contribution reference.'])->assertCreated();
        $result->assertJsonPath('draft.status', 'draft')->assertJsonPath('draft.correction_of_id', $id)->assertJsonPath('reversal.reversal_of_id', $id);
        $this->assertSame('reversed', JournalEntry::findOrFail($id)->status);
        $this->assertSame('0.00', app(AccountingService::class)->trialBalance(null, '2026-10-01')['rows']->firstWhere('code', '1000')['closing_balance']);
    }

    public function test_classification_and_control_mapping_survive_account_renumbering(): void
    {
        [$s, $a] = $this->context();
        $s->saveAccount([...$a['4000']->only(['name', 'type', 'normal_balance', 'is_posting', 'parent_id']), 'code' => 'REV-A', 'statement_group' => 'revenue'], $a['4000']);
        $s->saveAccount([...$a['5000']->only(['name', 'type', 'normal_balance', 'is_posting', 'parent_id']), 'code' => 'COST-A', 'statement_group' => 'cost_of_sales'], $a['5000']);
        $s->createAndPost(['posting_date' => '2026-09-30', 'description' => 'Classified sale', 'source_module' => 'test', 'source_key' => 'classified-sale', 'lines' => [
            ['accounting_account_id' => $a['1000']->id, 'debit' => 120], ['accounting_account_id' => $a['4000']->id, 'credit' => 120],
            ['accounting_account_id' => $a['5000']->id, 'debit' => 70], ['accounting_account_id' => $a['1200']->id, 'credit' => 70],
        ]]);
        $pl = $s->profitAndLoss('2026-09-01', '2026-09-30');
        $this->assertSame('120.00', $pl['revenue']);
        $this->assertSame('70.00', $pl['cost_of_goods_sold']);
        $this->assertSame('50.00', $pl['gross_profit']);
    }

    public function test_offsetting_unclassified_cash_flows_do_not_claim_complete_classification(): void
    {
        [$s, $a] = $this->context();
        $account = AccountingAccount::create($this->tenantAttributes(['code' => 'OTHER-X', 'name' => 'Unclassified counter account', 'type' => 'asset', 'normal_balance' => 'debit', 'is_posting' => true]));
        foreach ([false, true] as $out) {
            $s->createAndPost(['posting_date' => '2026-09-30', 'description' => 'Unclassified cash', 'source_module' => 'test', 'source_key' => 'cash-'.(int) $out, 'lines' => [
                ['accounting_account_id' => $a['1000']->id, $out ? 'credit' : 'debit' => 10], ['accounting_account_id' => $account->id, $out ? 'debit' : 'credit' => 10],
            ]]);
        }
        $flow = $s->cashFlow('2026-09-01', '2026-09-30');
        $this->assertSame('0.00', $flow['net_cash_change']);
        $this->assertTrue($flow['reconciles']);
        $this->assertFalse($flow['classification_complete']);
        $this->assertSame(2, $flow['unclassified_entries']);
    }
}
