<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\BankStatement;
use App\Models\BankStatementMatch;
use App\Models\Customer;
use App\Models\FinancialAccountTransaction;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\BankReconciliationService;
use App\Services\CustomerDebtService;
use App\Services\ExpenseService;
use App\Services\FinancialAccountService;
use App\Services\FinancialIntegrityChecks;
use App\Services\PermissionService;
use App\Support\CompanyClock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DC2BankControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_pages_are_bounded_and_foreign_company_ids_cannot_be_read_or_matched(): void
    {
        [$a,$bank,$service] = $this->context();
        $tx = $this->movement($a, $bank, 75, 'owner');
        $csv = "Date,Amount\n".str_repeat("2026-09-15,75.00\n", 61);
        $statement = $this->import($service, $bank, $csv);
        $this->assertCount(50, $statement->rows);
        $this->assertSame(61, $statement->pagination['total']);
        $this->getJson('/api/finance/bank-statements/'.$statement->id.'?page=2')->assertOk()->assertJsonCount(11, 'rows');
        $row = $statement->rows->first();
        User::query()->update(['api_token' => null]);
        $this->actingAsApiUser('admin');
        $this->getJson('/api/finance/bank-statements/'.$statement->id)->assertNotFound();
        $this->postJson('/api/finance/bank-statement-rows/'.$row->id.'/reconcile', ['transaction_id' => $tx->id])->assertNotFound();
        $this->assertSame(0, BankStatementMatch::withoutGlobalScopes()->count());
    }

    public function test_integrity_detects_raw_bank_ownership_corruption_without_repairing_history(): void
    {
        [$a,$bank,$service] = $this->context();
        $tx = $this->movement($a, $bank, 75, 'corruption');
        $statement = $this->import($service, $bank, "Date,Amount\n2026-09-15,75.00\n");
        $row = $statement->rows->sole();
        $service->reconcile($row, $tx);
        $before = JournalEntry::count();
        DB::table('bank_statement_matches')->where('bank_statement_row_id', $row->id)->update(['signed_amount' => 74]);
        $check = collect(app(FinancialIntegrityChecks::class)->checks())->firstWhere('code', 'bank_match_integrity');
        $this->assertFalse($check['passed']);
        $this->assertSame('critical', $check['severity']);
        $this->assertSame($before, JournalEntry::count());
        $this->assertDatabaseHas('bank_statement_matches', ['bank_statement_row_id' => $row->id, 'signed_amount' => 74]);
    }

    public function test_bank_provided_balances_cannot_be_changed_to_conceal_a_difference(): void
    {
        [$a,$bank,$service] = $this->context();
        $this->movement($a, $bank, 75, 'bank-evidence');
        $statement = $this->import($service, $bank, "Date,Amount,Balance\n2026-09-15,75.00,75.00\n", ['date' => 'Date', 'amount' => 'Amount', 'balance' => 'Balance']);
        $this->putJson('/api/finance/bank-statements/'.$statement->id.'/balances', ['opening_balance' => 10, 'closing_balance' => 85, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'reason' => 'Try to change the original bank evidence.'])->assertUnprocessable();
        $this->assertSame('0.00', $statement->fresh()->opening_balance);
        $this->assertSame('75.00', $statement->fresh()->closing_balance);
    }

    private function context(int $opening = 0): array
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        app(AccountingService::class)->initialize();
        $accounts = app(FinancialAccountService::class);
        $bank = $accounts->createAccount(['name' => 'DC2 bank', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => $opening, 'opening_date' => '2026-09-01']);

        return [$accounts, $bank, app(BankReconciliationService::class)];
    }

    private function movement($accounts, $bank, int $amount, string $key): FinancialAccountTransaction
    {
        return $accounts->post($bank, ['type' => 'inflow', 'amount' => $amount, 'transaction_date' => '2026-09-15', 'reference_number' => $key, 'idempotency_key' => $key, 'counter_accounting_account_id' => AccountingAccount::where('code', '3000')->firstOrFail()->id]);
    }

    private function import($service, $bank, string $contents, array $mapping = ['date' => 'Date', 'amount' => 'Amount']): BankStatement
    {
        return $service->import($bank, UploadedFile::fake()->createWithContent('bank.csv', $contents), $mapping);
    }

    private function balances($statement, int $opening, int $closing): void
    {
        $this->putJson('/api/finance/bank-statements/'.$statement->id.'/balances', ['opening_balance' => $opening, 'closing_balance' => $closing, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'reason' => 'Reviewed original bank statement balances.'])->assertOk();
    }

    public function test_import_stages_atomically_and_replay_does_not_create_financial_events(): void
    {
        [$a,$bank,$service] = $this->context();
        $tx = $this->movement($a, $bank, 75, 'known');
        $journals = JournalEntry::count();
        $statement = $this->import($service, $bank, "Date,Amount\n2026-09-15,75.00\n");
        $again = $this->import($service, $bank, "Date,Amount\n2026-09-15,75.00\n");
        $this->assertSame($statement->id, $again->id);
        $this->assertSame(1, BankStatement::count());
        $this->assertSame($journals, JournalEntry::count());
        $this->assertNull($statement->opening_balance);
        $this->assertSame('suggested', $statement->rows->sole()->status);
        $this->assertEquals($tx->id, $statement->rows->sole()->matched_transaction_id);
        try {
            $this->import($service, $bank, "Date,Amount\n2026-09-15,75.00\n2026-02-30,12.00\n");
            $this->fail('Invalid late row was ignored.');
        } catch (ValidationException) {
        }
        $this->assertSame(1, BankStatement::count());
        $this->assertDatabaseCount('financial_account_transactions', 1);
    }

    public function test_split_matching_keeps_exclusive_ownership_and_immutable_release_history(): void
    {
        [$a,$bank,$service] = $this->context();
        $one = $this->movement($a, $bank, 2000, 'invoice-A');
        $two = $this->movement($a, $bank, 2500, 'invoice-B');
        $three = $this->movement($a, $bank, 500, 'invoice-C');
        $statement = $this->import($service, $bank, "Date,Amount\n2026-09-15,5000.00\n2026-09-15,5000.00\n");
        $rows = $statement->rows->values();
        $journals = JournalEntry::count();
        $input = ['transaction_ids' => [$one->id, $two->id, $three->id]];
        $this->postJson('/api/finance/bank-statement-rows/'.$rows[0]->id.'/reconcile', $input)->assertOk();
        $this->postJson('/api/finance/bank-statement-rows/'.$rows[0]->id.'/reconcile', $input)->assertOk();
        $this->postJson('/api/finance/bank-statement-rows/'.$rows[1]->id.'/reconcile', $input)->assertUnprocessable();
        $this->assertSame(3, BankStatementMatch::whereNotNull('active_transaction_id')->count());
        $this->assertSame($journals, JournalEntry::count());
        try {
            $a->reverse($one, 'Must unmatch the recorded bank event first.');
            $this->fail('Matched transaction reversed.');
        } catch (ValidationException) {
        }
        $service->unmatch($rows[0], 'Correct the selection to the second bank row.');
        $service->reconcileMany($rows[1], [$one->id, $two->id, $three->id]);
        $this->assertSame(6, BankStatementMatch::count());
        $this->assertSame(3, BankStatementMatch::whereNotNull('released_at')->count());
        $this->assertDatabaseHas('bank_reconciliation_events', ['bank_statement_row_id' => $rows[0]->id, 'action' => 'unmatched']);
    }

    public function test_completion_requires_balance_proof_then_blocks_edits_backdated_activity_and_source_reversal(): void
    {
        [$a,$bank,$service] = $this->context(1000);
        $tx = $this->movement($a, $bank, 75, 'receipt');
        $statement = $this->import($service, $bank, "Date,Amount\n2026-09-15,75.00\n");
        $row = $statement->rows->sole();
        $this->postJson('/api/finance/bank-statements/'.$statement->id.'/complete')->assertUnprocessable();
        $this->balances($statement, 1000, 1074);
        $service->reconcile($row, $tx);
        $this->postJson('/api/finance/bank-statements/'.$statement->id.'/complete')->assertUnprocessable();
        $this->balances($statement, 1000, 1075);
        $r = $this->postJson('/api/finance/bank-statements/'.$statement->id.'/complete')->assertOk()->assertJsonPath('balance_control.difference', '0.00')->assertJsonPath('reconciliation_status', 'completed');
        $this->assertNotEmpty($r->json('reconciliation_proof.fingerprint'));
        $this->postJson('/api/finance/bank-statement-rows/'.$row->id.'/unmatch', ['reason' => 'Cannot edit a completed statement.'])->assertUnprocessable();
        try {
            $this->movement($a, $bank, 10, 'late-after-proof');
            $this->fail('Backdated movement invalidated completion.');
        } catch (ValidationException) {
        }
        $this->postJson('/api/finance/bank-statements/'.$statement->id.'/reopen', ['reason' => 'Review a source transaction correction.'])->assertOk();
        $service->unmatch($row, 'Release source ownership before its correction.');
        $a->reverse($tx, 'Incorrect original receipt.');
        $this->assertSame(CompanyClock::today()->toDateString(), $tx->fresh()->reversal_date->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'bank.statement_reopened', 'entity_id' => $statement->id]);
        $this->getJson('/api/finance/bank-statements/'.$statement->id)->assertOk()->assertJsonPath('balance_control.aims_closing_balance', '1075.00');
    }

    public function test_overlap_duplicate_evidence_blocks_confirmation_until_explicit_review(): void
    {
        [$a,$bank,$service] = $this->context();
        $this->movement($a, $bank, 75, 'first');
        $second = $this->movement($a, $bank, 75, 'second');
        $first = $this->import($service, $bank, "Date,Amount,Ref\n2026-09-15,75.00,DUP\n");
        $new = $this->import($service, $bank, "Date,Amount,Ref,Extra\n2026-09-15,75.00,DUP,export-two\n");
        $row = $new->rows->sole();
        $this->assertSame($first->rows->sole()->id, $row->duplicate_of_row_id);
        $this->postJson('/api/finance/bank-statement-rows/'.$row->id.'/reconcile', ['transaction_id' => $second->id])->assertUnprocessable();
        $this->postJson('/api/finance/bank-statement-rows/'.$row->id.'/review-duplicate', ['reason' => 'Bank confirmed this is a distinct second deposit.'])->assertOk();
        $this->postJson('/api/finance/bank-statement-rows/'.$row->id.'/reconcile', ['transaction_id' => $second->id])->assertOk();
    }

    public function test_required_bank_journey_f_matches_customer_supplier_expense_and_transfer_without_new_postings(): void
    {
        [$a,$bank,$service] = $this->context(10000);
        $cash = $a->createAccount(['name' => 'Cash', 'type' => 'cashbox', 'currency' => 'EUR', 'opening_balance' => 0, 'opening_date' => '2026-09-01']);
        $customer = Customer::create($this->tenantAttributes(['name' => 'Bank customer', 'current_debt' => 0, 'current_credit' => 0]));
        $debts = app(CustomerDebtService::class);
        $debts->addDebt($customer, ['amount' => 3700, 'transaction_date' => '2026-09-10', 'idempotency_key' => 'bank-sale']);
        $debts->recordPayment($customer, ['amount' => 3400, 'transaction_date' => '2026-09-15', 'payment_method' => 'bank_transfer', 'financial_account_id' => $bank->id, 'idempotency_key' => 'bank-receipt']);
        $expenses = app(ExpenseService::class);
        foreach ([['BILL', 1000, 600], ['FEE', 50, 50]] as [$ref,$gross,$paid]) {
            $bill = $expenses->create(['vendor_name' => 'Supplier '.$ref, 'vendor_business_number' => '811111111', 'document_type' => 'purchase_invoice', 'document_number' => $ref, 'source_type' => 'domestic', 'category' => 'professional_services', 'asset_treatment' => 'ordinary', 'business_purpose' => 'Company operations', 'invoice_date' => '2026-09-10', 'received_date' => '2026-09-10', 'currency' => 'EUR', 'net_amount' => $gross, 'vat_amount' => 0, 'vat_rate' => 0, 'vat_treatment' => 'non_vat', 'input_vat_eligible' => false]);
            $expenses->post($bill);
            $expenses->recordPayment($bill, ['amount' => $paid, 'payment_date' => '2026-09-15', 'payment_method' => 'bank_transfer', 'financial_account_id' => $bank->id, 'idempotency_key' => 'bank-'.$ref]);
        }
        $a->transfer(['source_account_id' => $bank->id, 'destination_account_id' => $cash->id, 'amount' => 250, 'transfer_date' => '2026-09-15', 'idempotency_key' => 'bank-transfer']);
        $statement = $this->import($service, $bank, "Date,Amount,Balance\n2026-09-15,3400.00,13400.00\n2026-09-15,-600.00,12800.00\n2026-09-15,-50.00,12750.00\n2026-09-15,-250.00,12500.00\n", ['date' => 'Date', 'amount' => 'Amount', 'balance' => 'Balance']);
        $before = JournalEntry::count();
        $transactions = $bank->transactions()->orderBy('id')->get();
        foreach ($statement->rows->values() as $i => $row) {
            $service->reconcile($row, $transactions[$i]);
        }
        $this->postJson('/api/finance/bank-statements/'.$statement->id.'/complete')->assertOk()->assertJsonPath('balance_control.difference', '0.00');
        $this->assertSame($before, JournalEntry::count());
        $this->assertTrue(app(AccountingService::class)->reconciliation(false)['all_reconciled']);
        $this->assertSame('300.00', $customer->fresh()->current_debt);
        $this->assertEquals(12500, $a->balance($bank));
        $this->assertEquals(250, $a->balance($cash));
    }

    public function test_required_month_close_journey_g_blocks_unreconciled_bank_activity_then_closed_date_posting(): void
    {
        [$a,$bank,$service] = $this->context();
        $tx = $this->movement($a, $bank, 75, 'month-close-item');
        $period = $this->postJson('/api/accounting/periods', ['name' => 'September DC2 close', 'starts_at' => '2026-09-01', 'ends_at' => '2026-09-30'])->assertCreated()->json('id');
        $close = ['status' => 'closed', 'reason' => 'Complete the reviewed month close.'];
        $this->putJson('/api/accounting/periods/'.$period.'/status', $close)->assertUnprocessable();
        $statement = $this->import($service, $bank, "Date,Amount,Balance\n2026-09-15,75.00,75.00\n", ['date' => 'Date', 'amount' => 'Amount', 'balance' => 'Balance']);
        $this->putJson('/api/accounting/periods/'.$period.'/status', $close)->assertUnprocessable();
        $service->reconcile($statement->rows->sole(), $tx);
        $service->complete($statement);
        $this->getJson('/api/accounting/periods/'.$period.'/readiness')->assertOk()->assertJsonPath('ready_to_close', true);
        $this->putJson('/api/accounting/periods/'.$period.'/status', $close)->assertOk()->assertJsonPath('status', 'closed');
        $this->postJson('/api/finance/accounts/'.$bank->id.'/transactions', ['type' => 'inflow', 'amount' => 10, 'transaction_date' => '2026-09-20', 'counter_accounting_account_id' => AccountingAccount::where('code', '3000')->firstOrFail()->id, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('code', 'accounting_period_locked');
        $this->assertFalse(app(PermissionService::class)->roleHasPermission('manager', 'bank_reconciliation.complete'));
        $this->assertTrue(app(PermissionService::class)->roleHasPermission('accountant', 'bank_reconciliation.complete'));
    }

    public function test_local_midnight_reversal_keeps_previous_day_statement_position(): void
    {
        [$a,$bank,$service] = $this->context();
        $this->apiCompany->update(['timezone' => 'Europe/Budapest']);
        Auth::user()->unsetRelation('company');
        Carbon::setTestNow('2026-10-31 23:30:00 UTC');
        try {
            $tx = $a->post($bank, ['type' => 'inflow', 'amount' => 100, 'transaction_date' => '2026-10-31', 'counter_accounting_account_id' => AccountingAccount::where('code', '3000')->firstOrFail()->id, 'idempotency_key' => 'midnight-receipt']);
            $a->reverse($tx, 'Reverse in the next company-local day.');
            $this->assertSame('2026-11-01', $tx->fresh()->reversal_date->toDateString());
            $statement = $this->import($service, $bank, "Date,Amount\n2026-10-31,100.00\n");
            $this->assertSame('100.00', $statement->balance_control['aims_closing_balance']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_month_close_rechecks_completed_statement_evidence_instead_of_trusting_status(): void
    {
        [$a,$bank,$service] = $this->context();
        $tx = $this->movement($a, $bank, 75, 'completed-proof');
        $statement = $this->import($service, $bank, "Date,Amount,Balance\n2026-09-15,75.00,75.00\n", ['date' => 'Date', 'amount' => 'Amount', 'balance' => 'Balance']);
        $service->reconcile($statement->rows->sole(), $tx);
        $this->balances($statement, 0, 75);
        $this->postJson('/api/finance/bank-statements/'.$statement->id.'/complete')->assertOk();
        $period = app(AccountingService::class)->savePeriod(['name' => 'September proof', 'starts_at' => '2026-09-01', 'ends_at' => '2026-09-30']);
        $this->assertTrue(app(AccountingService::class)->periodReadiness($period)['ready_to_close']);
        DB::table('bank_statements')->where('id', $statement->id)->update(['closing_balance' => 76]);
        $readiness = app(AccountingService::class)->periodReadiness($period);
        $this->assertFalse($readiness['ready_to_close']);
        $this->assertSame(1, collect($readiness['checks'])->firstWhere('code', 'bank_completion_proofs')['count']);
        $this->assertDatabaseHas('bank_statements', ['id' => $statement->id, 'reconciliation_status' => 'completed', 'closing_balance' => 76]);
    }

    public function test_manual_bank_classification_cannot_bypass_customer_supplier_or_inventory_subledgers(): void
    {
        [$accounts,$bank] = $this->context();
        foreach (['1100', '2000', '1200', '1300', '2200', '1000'] as $code) {
            $before = JournalEntry::count();
            $this->postJson('/api/finance/accounts/'.$bank->id.'/transactions', ['type' => 'inflow', 'amount' => 20, 'transaction_date' => '2026-09-15', 'idempotency_key' => (string) Str::uuid(), 'counter_accounting_account_id' => AccountingAccount::where('code', $code)->firstOrFail()->id])->assertUnprocessable();
            $this->assertSame($before, JournalEntry::count());
            $this->assertSame(0, $bank->transactions()->count());
        }
    }
}
