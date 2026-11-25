<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\AccountingPostingMapping;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingChartIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): void
    {
        // Payroll setup checks a recently authenticated full session.
        $session = app(\App\Services\JwtService::class)->issueTokens($user);
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$session['access_token']);
    }

    private function account(string $key): AccountingAccount
    {
        return AccountingPostingMapping::where('mapping_key', $key)->firstOrFail()->account;
    }

    public function test_payroll_and_assets_initialize_in_both_orders_and_preserve_custom_accounts(): void
    {
        $this->actingAsApiUser();
        $first = User::where('company_id', $this->apiCompany->id)->firstOrFail();
        $this->login($first);
        $this->postJson('/api/payroll/configuration/initialize')->assertOk()->assertJsonCount(0, 'mapping_issues');
        $this->postJson('/api/accounting/initialize')->assertOk();
        $salary = $this->account('payroll_salary_expense');
        $this->assertSame('6500', $salary->code);
        $this->assertSame('6510', $this->account('payroll_employer_pension_expense')->code);
        $this->assertSame('1500', $this->account('payroll_employee_advances')->code);
        $this->assertSame('2300', $this->account('payroll_salary_payable')->code);
        $this->assertSame('2350', $this->account('corporate_tax_payable')->code);
        $this->assertSame('1530', $this->account('fixed_asset_cost')->code);
        $this->assertSame('6530', $this->account('fixed_asset_depreciation')->code);

        $other = Company::factory()->create();
        $second = User::factory()->create(['company_id' => $other->id, 'role' => 'admin', 'email_verified_at' => now()]);
        $this->login($second);
        $this->postJson('/api/accounting/initialize')->assertOk();
        $this->postJson('/api/payroll/configuration/initialize')->assertOk();
        $this->putJson('/api/accounting/mappings', ['mappings' => ['fixed_asset_depreciation' => $salary->id]])->assertNotFound();
        $custom = app(AccountingService::class)->saveAccount(['code' => '6719', 'name' => 'Equipment write-down expense', 'type' => 'expense',
            'normal_balance' => 'debit', 'is_posting' => true, 'is_active' => true, 'statement_group' => 'operating_expense']);
        $this->putJson('/api/accounting/mappings', ['mappings' => ['fixed_asset_impairment' => $custom->id]])->assertOk();
        $count = AccountingAccount::count();
        $this->postJson('/api/accounting/initialize')->assertOk();
        $this->postJson('/api/payroll/configuration/initialize')->assertOk();
        $this->assertSame($count, AccountingAccount::count());
        $this->assertSame($custom->id, $this->account('fixed_asset_impairment')->id);
        $this->assertSame($other->id, $this->account('payroll_salary_expense')->company_id);
    }

    public function test_legacy_shared_purpose_is_read_only_until_review_and_blocks_new_posting(): void
    {
        $this->actingAsApiUser();
        $this->login(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        $this->postJson('/api/payroll/configuration/initialize')->assertOk();
        $salary = $this->account('payroll_salary_expense');
        $depreciation = $this->account('fixed_asset_depreciation');
        // Reproduce an imported DC39.1 chart and a historically affected journal.
        $history = app(AccountingService::class)->createAndPost(['posting_date' => '2025-12-31', 'description' => 'Synthetic historical depreciation',
            'source_module' => 'fixed_assets', 'source_type' => 'historical_fixture', 'currency' => 'EUR',
            'lines' => [['accounting_account_id' => $salary->id, 'debit' => 10],
                ['accounting_account_id' => $this->account('fixed_asset_accumulated_depreciation')->id, 'credit' => 10]]]);
        DB::table('accounting_posting_mappings')->where('company_id', $this->apiCompany->id)
            ->where('mapping_key', 'fixed_asset_depreciation')->update(['accounting_account_id' => $salary->id]);
        $issues = $this->getJson('/api/accounting/controls')->assertOk()->json('mapping_issues');
        $this->assertSame([['mapping' => 'fixed_asset_depreciation', 'code' => '6500', 'reason' => 'shared_account', 'prior_postings' => 1]], $issues);
        $this->postJson('/api/accounting/initialize')->assertOk();
        $this->assertSame($salary->id, $this->account('fixed_asset_depreciation')->id);
        $this->postJson('/api/payroll/configuration/initialize')->assertUnprocessable();
        $this->putJson('/api/accounting/mappings', ['mappings' => ['fixed_asset_depreciation' => $depreciation->id]])->assertUnprocessable()
            ->assertJsonValidationErrors('fixed_asset_depreciation');
        try {
            app(AccountingService::class)->postMapped('fixed_assets', 'historical_fixture', 2, 'test:asset-conflict', '2025-12-31', 'Blocked synthetic depreciation', [
                ['mapping' => 'fixed_asset_depreciation', 'debit' => 10],
                ['mapping' => 'fixed_asset_accumulated_depreciation', 'credit' => 10],
            ]);
            $this->fail('The legacy shared mapping was used for a new posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fixed_asset_depreciation', $exception->errors());
        }
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame('10.00', $history->fresh()->lines->first()->debit);
    }
}
