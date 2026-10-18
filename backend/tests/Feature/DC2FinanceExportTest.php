<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class DC2FinanceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_exports_posted_history_only_and_protects_spreadsheet_formulas(): void
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        $service = app(AccountingService::class);
        $service->initialize();
        $lines = [['accounting_account_id' => AccountingAccount::where('code', '6000')->firstOrFail()->id, 'debit' => 125], ['accounting_account_id' => AccountingAccount::where('code', '3000')->firstOrFail()->id, 'credit' => 125]];
        $journal = $service->createAndPost(['posting_date' => '2026-09-15', 'description' => 'Posted export evidence', 'reference_number' => '=HYPERLINK("https://invalid.example")', 'source_module' => 'manual', 'lines' => $lines]);
        $service->createDraft(['posting_date' => '2026-09-15', 'description' => 'DRAFT-EXCLUDED', 'source_module' => 'manual', 'lines' => $lines]);
        $before = JournalEntry::count();
        $response = $this->getJson('/api/accounting/exports/gl?from=2026-09-01&to=2026-09-30')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString($journal->journal_number, $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('DRAFT-EXCLUDED', $csv);
        $this->assertStringContainsString('125.00', $csv);
        $this->assertSame($before, JournalEntry::count());
        foreach (['trial', 'pl', 'balance', 'cash', 'ar', 'ap'] as $report) {
            $this->getJson('/api/accounting/exports/'.$report.'?from=2026-09-01&to=2026-09-30')->assertOk();
        }
    }

    public function test_general_ledger_export_cannot_reach_another_company_account(): void
    {
        $this->actingAsApiUser('admin');
        $owner = User::where('company_id', $this->apiCompany->id)->firstOrFail();
        Auth::setUser($owner);
        $service = app(AccountingService::class);
        $service->initialize();
        $other = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        Auth::setUser($user);
        $service->initialize();
        $account = AccountingAccount::where('code', '6000')->firstOrFail();
        $journal = $service->createAndPost(['posting_date' => '2026-09-15', 'description' => 'FOREIGN-COMPANY-SECRET', 'source_module' => 'manual', 'lines' => [['accounting_account_id' => $account->id, 'debit' => 987], ['accounting_account_id' => AccountingAccount::where('code', '3000')->firstOrFail()->id, 'credit' => 987]]]);
        Auth::setUser($owner);
        $csv = $this->getJson('/api/accounting/exports/gl?account_id='.$account->id.'&to=2026-09-30')->assertOk()->streamedContent();
        $this->assertStringNotContainsString($journal->journal_number, $csv);
        $this->assertStringNotContainsString('987.00',$csv);
    }
}
