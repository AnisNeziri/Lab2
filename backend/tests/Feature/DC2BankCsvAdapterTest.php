<?php

namespace Tests\Feature;

use App\Services\BankStatementCsvAdapter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DC2BankCsvAdapterTest extends TestCase
{
    public function test_mapped_european_csv_uses_explicit_conventions_and_bank_running_balances(): void
    {
        $r = app(BankStatementCsvAdapter::class)->parse("Booked;Reference;Value;Balance\n01/09/2026;INV-A;1.250,50;2.250,50\n02/09/2026;FEE;-5,00;2.245,50\n", ['date' => 'Booked', 'reference' => 'Reference', 'amount' => 'Value', 'balance' => 'Balance'], ['date_format' => 'd/m/Y', 'decimal_separator' => 'comma', 'thousands_separator' => 'dot']);
        $this->assertSame('1000.00', $r['opening_balance']);
        $this->assertSame('2245.50', $r['closing_balance']);
        $this->assertSame('2026-09-01', $r['rows'][0]['date']);
        $this->assertSame('1250.50', $r['rows'][0]['amount']);
    }

    public function test_unknown_balances_stay_unknown_and_duplicate_same_file_rows_are_preserved(): void
    {
        $r = app(BankStatementCsvAdapter::class)->parse("Date,Amount\n2026-09-01,75.00\n2026-09-01,75.00\n", ['date' => 'Date', 'amount' => 'Amount']);
        $this->assertNull($r['opening_balance']);
        $this->assertNull($r['closing_balance']);
        $this->assertCount(2, $r['rows']);
    }

    public function test_reverse_order_and_separate_debit_credit_columns_are_explicit(): void
    {
        $r = app(BankStatementCsvAdapter::class)->parse("Date,Debit,Credit,Balance\n2026-09-02,5.00,,170.00\n2026-09-01,,75.00,175.00\n", ['date' => 'Date', 'debit' => 'Debit', 'credit' => 'Credit', 'balance' => 'Balance'], ['row_order' => 'descending']);
        $this->assertSame('100.00', $r['opening_balance']);
        $this->assertSame('170.00', $r['closing_balance']);
    }

    public function test_bad_dates_amounts_column_counts_and_running_balances_cannot_be_silently_skipped(): void
    {
        $files = ["Date,Amount\n09/01/2026,10.00\n", "Date,Amount\n2026-02-30,10.00\n", "Date,Amount\n2026-09-01,not-money\n", "Date,Amount\n2026-09-01,0.00\n", "Date,Amount\n2026-09-01,10.00,unexpected\n"];
        foreach ($files as $file) {
            try {
                app(BankStatementCsvAdapter::class)->parse($file, ['date' => 'Date', 'amount' => 'Amount']);
                $this->fail('Invalid CSV silently accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('file', $e->errors());
            }
        }
        $this->expectException(ValidationException::class);
        app(BankStatementCsvAdapter::class)->parse("Date,Amount,Balance\n2026-09-01,75.00,175.00\n2026-09-02,-5.00,169.00\n", ['date' => 'Date', 'amount' => 'Amount', 'balance' => 'Balance']);
    }
}
