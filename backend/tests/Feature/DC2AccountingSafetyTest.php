<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class DC2AccountingSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        $service = app(AccountingService::class);
        $service->initialize();

        return [$service, collect($service->accounts())->keyBy('code')];
    }

    private function entry(AccountingService $service, $accounts): JournalEntry
    {
        return $service->createAndPost(['posting_date' => '2026-09-10', 'description' => 'Immutable source',
            'source_module' => 'safety_test', 'source_key' => 'immutable', 'lines' => [
                ['accounting_account_id' => $accounts['1000']->id, 'debit' => '100.00'],
                ['accounting_account_id' => $accounts['3000']->id, 'credit' => '100.00'],
            ]]);
    }

    public function test_financial_fields_cannot_change_alongside_a_posted_status_transition(): void
    {
        [$service, $accounts] = $this->context();
        $entry = $this->entry($service, $accounts);
        $this->expectException(LogicException::class);
        $entry->update(['description' => 'Hidden rewrite', 'status' => 'reversed']);
    }

    public function test_reversed_journal_header_remains_immutable(): void
    {
        [$service, $accounts] = $this->context();
        $entry = $this->entry($service, $accounts);
        $service->reverse($entry, '2026-09-11', 'Correct the original error.');
        $this->expectException(LogicException::class);
        $entry->fresh()->update(['posting_date' => '2026-09-12']);
    }

    public function test_reversed_journal_lines_remain_immutable(): void
    {
        [$service, $accounts] = $this->context();
        $entry = $this->entry($service, $accounts);
        $service->reverse($entry, '2026-09-11', 'Correct the original error.');
        $this->expectException(LogicException::class);
        $entry->lines()->first()->update(['debit' => '900.00']);
    }

    public function test_lines_cannot_be_appended_after_posting(): void
    {
        [$service, $accounts] = $this->context();
        $entry = $this->entry($service, $accounts);
        $this->expectException(LogicException::class);
        $entry->lines()->create(['accounting_account_id' => $accounts['1000']->id, 'line_number' => 3, 'debit' => 5, 'credit' => 0]);
    }

    public function test_manual_http_request_cannot_impersonate_an_operational_source(): void
    {
        [, $accounts] = $this->context();
        $this->postJson('/api/accounting/journals', ['posting_date' => '2026-09-10', 'description' => 'Spoofed source',
            'source_module' => 'inventory', 'source_type' => 'stock_movement', 'source_id' => 12, 'lines' => [
                ['accounting_account_id' => $accounts['1000']->id, 'debit' => 5],
                ['accounting_account_id' => $accounts['3000']->id, 'credit' => 5],
            ]])->assertUnprocessable();
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_referenced_control_account_cannot_be_deactivated(): void
    {
        [$service, $accounts] = $this->context();
        $this->entry($service, $accounts);
        $this->expectException(ValidationException::class);
        $service->archiveAccount($accounts['1000']);
    }

    public function test_trial_balance_uses_bounded_grouped_queries(): void
    {
        [$service, $accounts] = $this->context();
        $this->entry($service, $accounts);
        for ($i = 0; $i < 40; $i++) {
            AccountingAccount::create($this->tenantAttributes([
                'code' => 'X'.$i, 'name' => 'Extra account '.$i, 'type' => 'expense', 'normal_balance' => 'debit', 'is_posting' => true,
            ]));
        }
        DB::enableQueryLog();
        $result = $service->trialBalance('2026-09-01', '2026-09-30');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertTrue($result['balanced']);
        $this->assertLessThanOrEqual(8, $count, 'Trial balance query count must not grow once per account.');
    }
}
