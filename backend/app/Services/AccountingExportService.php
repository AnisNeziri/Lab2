<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Supplier;
use App\Support\CompanyClock;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bounded, consistent CSV evidence; the posted ledger remains authoritative. */
class AccountingExportService
{
    public function export(string $report, array $filters)
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? CompanyClock::today()->toDateString();
        $stream = fopen('php://temp/maxmemory:2097152', 'w+');
        try {
            DB::transaction(function () use ($report, $filters, $from, $to, $stream) {
                $rows = null;
                $query = null;
                switch ($report) {
                    case 'trial':
                        $rows = app(AccountingService::class)->trialBalance($from, $to)['rows'];
                        $rows = collect($rows)->filter(fn ($a) => (empty($filters['account_from']) || strcmp($a['code'], $filters['account_from']) >= 0) && (empty($filters['account_to']) || strcmp($a['code'], $filters['account_to']) <= 0));
                        $columns = ['code', 'name', 'opening_debit', 'opening_credit', 'debit_movement', 'credit_movement', 'closing_debit', 'closing_credit'];
                        break;
                    case 'pl':
                    case 'balance':
                    case 'cash':
                        $accounting = app(AccountingService::class);
                        $data = match ($report) {
                            'pl' => $accounting->profitAndLoss($from, $to), 'balance' => $accounting->balanceSheet($to), 'cash' => $accounting->cashFlow($from, $to)
                        };
                        $rows = collect($data)->filter(fn ($v) => is_scalar($v) || $v === null)->map(fn ($v, $key) => ['metric' => $key, 'value' => $v])->values();
                        $columns = ['metric', 'value'];
                        break;
                    case 'gl':
                        $query = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
                            ->where('j.company_id', Auth::user()->company_id)->where('a.company_id', Auth::user()->company_id)->whereIn('j.status', ['posted', 'reversed'])
                            ->when($from, fn ($q) => $q->where('j.posting_date', '>=', $from))->whereDate('j.posting_date', '<=', $to)
                            ->when($filters['account_id'] ?? null, fn ($q, $v) => $q->where('a.id', $v))
                            ->select('j.journal_number', 'j.posting_date', 'j.reference_number', 'j.source_module', 'j.source_type', 'j.source_id', 'a.code', 'a.name', 'l.description', 'l.debit', 'l.credit', 'j.currency', 'l.foreign_debit', 'l.foreign_credit')
                            ->orderBy('j.posting_date')->orderBy('j.id')->orderBy('l.line_number')->orderBy('l.id');
                        $columns = ['journal_number', 'posting_date', 'reference_number', 'source_module', 'source_type', 'source_id', 'code', 'name', 'description', 'debit', 'credit', 'currency', 'foreign_debit', 'foreign_credit'];
                        break;
                    case 'ar':
                        $this->permit('debts.view');
                        $query = app(ReceivableReports::class)->positions($to, $filters)->orderBy('customer_id')->orderBy('source')->orderBy('source_id');
                        $columns = ['customer_id', 'customer_name', 'reference', 'invoice_date', 'due_date', 'currency', 'outstanding', 'legacy_snapshot'];
                        break;
                    case 'ap':
                        $this->permit('finance.view');
                        $query = app(PayableReports::class)->positions($to, $filters)->orderBy('supplier_id')->orderBy('id');
                        $columns = ['supplier_id', 'vendor_name', 'document_number', 'invoice_date', 'due_date', 'currency', 'outstanding', 'base_outstanding'];
                        break;
                    case 'customer-statement':
                        $this->permit('debts.view');
                        validator($filters, ['customer_id' => 'required|integer|min:1', 'from' => 'required|date'])->validate();
                        $events = app(ReceivableReports::class)->statementEvents(Customer::findOrFail($filters['customer_id']), $to, $filters['currency'] ?? CompanyCurrency::current());
                        $running = $events->select('events.*')->selectRaw('SUM(ar_delta) OVER (ORDER BY DATE(date),source,source_id) ar_balance,SUM(advance_delta) OVER (ORDER BY DATE(date),source,source_id) advance_balance');
                        $query = DB::query()->fromSub($running, 'customer_statement')->whereDate('date', '>=', $from)->orderByRaw('DATE(date)')->orderBy('source')->orderBy('source_id');
                        $columns = ['date', 'type', 'reference', 'description', 'currency', 'ar_delta', 'advance_delta', 'ar_balance', 'advance_balance'];
                        break;
                    case 'supplier-statement':
                        $this->permit('finance.view');
                        validator($filters, ['supplier_id' => 'required|integer|min:1', 'from' => 'required|date'])->validate();
                        $query = app(SupplierStatementQueries::class)->running(Supplier::findOrFail($filters['supplier_id']), $to)->whereDate('date', '>=', $from)->orderByRaw('DATE(date)')->orderBy('sort_order')->orderBy('source')->orderBy('source_id');
                        $columns = ['date', 'source', 'reference', 'description', 'currency', 'transaction_amount', 'ap_delta', 'advance_delta', 'payable_balance', 'advance_balance'];
                        break;
                    default: abort(404);
                }
                if ($query && (clone $query)->reorder()->count() > 100000) {
                    throw ValidationException::withMessages(['export' => 'Narrow the dates or account selection to at most 100,000 rows.']);
                }
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, array_map(fn ($c) => ucwords(str_replace('_', ' ', $c)), $columns), ',', '"', '');
                $write = function ($row) use ($stream, $columns) {
                    $row = (array) $row;
                    foreach (['opening_debit', 'opening_credit', 'debit_movement', 'credit_movement', 'closing_debit', 'closing_credit', 'debit', 'credit', 'foreign_debit', 'foreign_credit', 'outstanding', 'base_outstanding', 'ar_delta', 'advance_delta', 'ar_balance', 'advance_balance', 'ap_delta', 'transaction_amount', 'payable_balance'] as $moneyColumn) {
                        if (array_key_exists($moneyColumn, $row)) {
                            $row[$moneyColumn] = Money::normalize($row[$moneyColumn]);
                        }
                    }
                    if (isset($row['source_type'])) {
                        $row['source_type'] = AccountingJournalExperience::SOURCES[$row['source_type']][1] ?? 'Financial Journal';
                    }
                    fputcsv($stream, array_map(fn ($key) => $this->safeCell($row[$key] ?? ''), $columns), ',', '"', '');
                };
                if ($query) {
                    $query->chunk(1000, fn ($chunk) => $chunk->each($write));
                } else {
                    foreach ($rows as $row) {
                        $write($row);
                    }
                }
            });
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }
        rewind($stream);

        return response()->streamDownload(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 'aims-'.$report.'-'.$to.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function safeCell(mixed $value): string
    {
        $value = (string) $value;
        if (preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) && ! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            return "'".$value;
        }

        return $value;
    }

    private function permit(string $permission): void
    {
        abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, $permission), 403);
    }
}
