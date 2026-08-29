<?php

namespace Tests\Feature;

use App\Models\FinancialAccountTransaction;
use App\Models\User;
use App\Services\BankReconciliationService;
use App\Services\FinancialAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_transfer_and_reversal_keep_one_balanced_money_history(): void
    {
        $accounts = $this->financeContext();
        $cash = $accounts->createAccount([
            'type' => 'cashbox', 'name' => 'Main cashbox', 'currency' => 'EUR',
            'opening_balance' => 100, 'opening_date' => '2026-08-29',
        ]);
        $bank = $accounts->createAccount([
            'type' => 'bank', 'name' => 'Main bank', 'currency' => 'EUR',
            'opening_balance' => 0, 'opening_date' => '2026-08-29',
        ]);

        $transfer = $accounts->transfer([
            'source_account_id' => $cash->id,
            'destination_account_id' => $bank->id,
            'amount' => 40,
            'transfer_date' => '2026-08-29',
            'reference_number' => 'TR-001',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertEqualsWithDelta(60, $accounts->balance($cash), .001);
        $this->assertEqualsWithDelta(40, $accounts->balance($bank), .001);
        $out = FinancialAccountTransaction::query()
            ->where('source_type', 'financial_account_transfer')
            ->where('source_id', $transfer->id)
            ->where('type', 'transfer_out')
            ->firstOrFail();

        $accounts->reverse($out, 'Transfer entered against the wrong accounts.');

        $this->assertEqualsWithDelta(100, $accounts->balance($cash), .001);
        $this->assertEqualsWithDelta(0, $accounts->balance($bank), .001);
        $this->assertSame(2, FinancialAccountTransaction::query()->where('status', 'reversed')->count());
    }

    public function test_bank_csv_import_suggests_but_does_not_auto_confirm_a_match(): void
    {
        $accounts = $this->financeContext();
        $bank = $accounts->createAccount([
            'type' => 'bank', 'name' => 'Statement bank', 'currency' => 'EUR',
            'opening_balance' => 0, 'opening_date' => '2026-08-29',
        ]);
        $transaction = $accounts->post($bank, [
            'type' => 'inflow', 'amount' => 75, 'transaction_date' => '2026-08-29',
            'reference_number' => 'PAY-75', 'description' => 'Customer payment',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'statement.csv',
            "Date,Description,Reference,Amount,Balance\n2026-08-29,Customer payment,PAY-75,75.00,1075.00\n",
        );

        $statement = app(BankReconciliationService::class)->import($bank, $file, [
            'date' => 'Date', 'description' => 'Description',
            'reference' => 'Reference', 'amount' => 'Amount', 'balance' => 'Balance',
        ]);
        $row = $statement->rows->sole();

        $this->assertSame('suggested', $row->status);
        $this->assertSame($transaction->id, $row->matched_transaction_id);
        $this->assertEqualsWithDelta(1075, (float) $statement->closing_balance, .001);
        $this->assertDatabaseMissing('bank_statement_rows', ['id' => $row->id, 'status' => 'reconciled']);
    }

    private function financeContext(): FinancialAccountService
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(User::query()->where('company_id', $this->apiCompany->id)->firstOrFail());

        return app(FinancialAccountService::class);
    }
}
