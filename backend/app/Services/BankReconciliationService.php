<?php

namespace App\Services;

use App\Models\BankReconciliationEvent;
use App\Models\BankStatement;
use App\Models\BankStatementRow;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationService
{
    public function statements(): mixed
    {
        return BankStatement::query()->with('account:id,name,currency')
            ->withCount(['rows', 'rows as reconciled_rows_count' => fn ($q) => $q->where('status', 'reconciled')])
            ->latest()->paginate(20);
    }

    public function import(FinancialAccount $account, UploadedFile $file, array $mapping, string $delimiter = 'auto'): BankStatement
    {
        if ($account->type !== 'bank') {
            throw ValidationException::withMessages(['financial_account_id' => ['Statements can be imported only into a bank account.']]);
        }
        $contents = $file->get();
        $hash = hash('sha256', $contents);
        if ($existing = BankStatement::query()->where('financial_account_id', $account->id)->where('file_hash', $hash)->first()) {
            return $this->show($existing);
        }

        $separator = $delimiter === 'auto' ? $this->detectDelimiter(strtok($contents, "\r\n") ?: '') : $delimiter;
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);
        $headers = fgetcsv($handle, 0, $separator);
        if (! $headers) throw ValidationException::withMessages(['file' => ['The CSV file has no header row.']]);
        $headers = array_map(fn ($value) => trim((string) $value), $headers);
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        $mapping = array_map(
            fn ($value) => is_string($value) ? preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) : $value,
            $mapping,
        );
        if (empty($mapping['date'])) {
            throw ValidationException::withMessages(['column_mapping' => ['Map a valid date column.']]);
        }
        if (empty($mapping['amount']) && (empty($mapping['debit']) || empty($mapping['credit']))) {
            throw ValidationException::withMessages(['column_mapping' => ['Map amount, or map both debit and credit columns.']]);
        }
        foreach (['date', 'amount', 'debit', 'credit', 'description', 'reference', 'balance'] as $field) {
            if (! empty($mapping[$field]) && ! in_array($mapping[$field], $headers, true)) {
                throw ValidationException::withMessages(['column_mapping' => ["The mapped {$field} column does not exist in the CSV header."]]);
            }
        }

        return DB::transaction(function () use ($account, $file, $hash, $mapping, $separator, $headers, $handle) {
            $statement = BankStatement::create([
                'company_id' => $account->company_id, 'financial_account_id' => $account->id,
                'file_name' => basename($file->getClientOriginalName()), 'file_hash' => $hash,
                'column_mapping' => $mapping, 'imported_by' => Auth::id(),
            ]);
            $dates = [];
            $lastBalance = null;
            $rowNumber = 1;
            while (($values = fgetcsv($handle, 0, $separator)) !== false) {
                $rowNumber++;
                if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) continue;
                $record = array_combine($headers, array_pad(array_slice($values, 0, count($headers)), count($headers), ''));
                $date = $this->parseDate((string) ($record[$mapping['date']] ?? ''));
                $amount = $this->parseAmount($record, $mapping);
                if ($date === null || abs($amount) < .005) continue;
                $description = trim((string) ($record[$mapping['description'] ?? ''] ?? '')) ?: null;
                $reference = trim((string) ($record[$mapping['reference'] ?? ''] ?? '')) ?: null;
                $balance = isset($mapping['balance']) ? $this->number($record[$mapping['balance']] ?? '') : null;
                // The row number preserves two legitimate identical bank rows
                // while the statement file hash still prevents duplicate imports.
                $rowHash = hash('sha256', implode('|', [$rowNumber, $date, number_format($amount, 2, '.', ''), $reference, $description]));
                BankStatementRow::firstOrCreate(
                    ['bank_statement_id' => $statement->id, 'row_hash' => $rowHash],
                    [
                        'company_id' => $account->company_id, 'transaction_date' => $date,
                        'description' => $description, 'reference_number' => $reference,
                        'amount' => $amount, 'statement_balance' => $balance, 'status' => 'unmatched',
                        'raw_data' => $record,
                    ],
                );
                $dates[] = $date;
                if ($balance !== null) $lastBalance = $balance;
            }
            fclose($handle);
            if ($dates) {
                sort($dates);
                $statement->update([
                    'period_from' => $dates[0],
                    'period_to' => $dates[count($dates) - 1],
                    'closing_balance' => $lastBalance,
                ]);
            }
            $this->refreshSuggestions($statement);

            return $this->show($statement->fresh());
        });
    }

    public function show(BankStatement $statement): BankStatement
    {
        return $statement->load(['account:id,name,currency', 'rows' => fn ($q) => $q->with('matchedTransaction')->orderBy('transaction_date')->orderBy('id')]);
    }

    public function suggestions(BankStatementRow $row): array
    {
        $statement = $row->statement;
        return FinancialAccountTransaction::query()
            ->where('financial_account_id', $statement->financial_account_id)
            ->where('status', 'posted')
            ->whereBetween('transaction_date', [
                $row->transaction_date->copy()->subDays(5)->toDateString(),
                $row->transaction_date->copy()->addDays(5)->toDateString(),
            ])->get()->map(function (FinancialAccountTransaction $transaction) use ($row) {
                $signed = $this->signedAmount($transaction);
                $amountScore = abs($signed - (float) $row->amount) < .005 ? 60 : 0;
                $days = abs($transaction->transaction_date->diffInDays($row->transaction_date));
                $dateScore = max(0, 30 - ($days * 6));
                $referenceScore = $row->reference_number && $transaction->reference_number
                    && str_contains(mb_strtolower($transaction->reference_number), mb_strtolower($row->reference_number)) ? 10 : 0;
                $transaction->setAttribute('match_score', $amountScore + $dateScore + $referenceScore);
                $transaction->setAttribute('signed_amount', $signed);
                return $transaction;
            })->filter(fn ($transaction) => $transaction->match_score >= 60)
                ->sortByDesc('match_score')->values()->take(10)->all();
    }

    public function reconcile(BankStatementRow $row, FinancialAccountTransaction $transaction): BankStatementRow
    {
        return DB::transaction(function () use ($row, $transaction) {
            $locked = BankStatementRow::query()->lockForUpdate()->findOrFail($row->id);
            $statement = $locked->statement;
            if ((int) $transaction->financial_account_id !== (int) $statement->financial_account_id || $transaction->status !== 'posted') {
                throw ValidationException::withMessages(['transaction_id' => ['Choose an active transaction from the same bank account.']]);
            }
            if (abs($this->signedAmount($transaction) - (float) $locked->amount) >= .005) {
                throw ValidationException::withMessages(['transaction_id' => ['The statement and system transaction amounts must match exactly.']]);
            }
            $used = BankStatementRow::query()->where('matched_transaction_id', $transaction->id)
                ->where('status', 'reconciled')->whereKeyNot($locked->id)->exists();
            if ($used) throw ValidationException::withMessages(['transaction_id' => ['That system transaction is already reconciled.']]);
            $old = $locked->toArray();
            $locked->update([
                'status' => 'reconciled', 'matched_transaction_id' => $transaction->id,
                'match_score' => 100, 'ignore_reason' => null, 'reviewed_by' => Auth::id(), 'reviewed_at' => now(),
            ]);
            $this->event($locked, 'reconciled', $transaction->id, null, $old, $locked->fresh()->toArray());

            return $locked->fresh(['matchedTransaction', 'statement.account']);
        });
    }

    public function ignore(BankStatementRow $row, string $reason): BankStatementRow
    {
        return DB::transaction(function () use ($row, $reason) {
            $locked = BankStatementRow::query()->lockForUpdate()->findOrFail($row->id);
            $old = $locked->toArray();
            $locked->update([
                'status' => 'ignored', 'matched_transaction_id' => null, 'match_score' => null,
                'ignore_reason' => trim($reason), 'reviewed_by' => Auth::id(), 'reviewed_at' => now(),
            ]);
            $this->event($locked, 'ignored', null, $reason, $old, $locked->fresh()->toArray());
            return $locked->fresh();
        });
    }

    public function unmatch(BankStatementRow $row, string $reason): BankStatementRow
    {
        return DB::transaction(function () use ($row, $reason) {
            $locked = BankStatementRow::query()->lockForUpdate()->findOrFail($row->id);
            $old = $locked->toArray();
            $transactionId = $locked->matched_transaction_id;
            $locked->update([
                'status' => 'unmatched', 'matched_transaction_id' => null, 'match_score' => null,
                'ignore_reason' => null, 'reviewed_by' => Auth::id(), 'reviewed_at' => now(),
            ]);
            $this->event($locked, 'unmatched', $transactionId, $reason, $old, $locked->fresh()->toArray());
            return $locked->fresh();
        });
    }

    private function refreshSuggestions(BankStatement $statement): void
    {
        foreach ($statement->rows()->where('status', 'unmatched')->get() as $row) {
            $best = collect($this->suggestions($row))->first();
            if ($best) $row->update(['status' => 'suggested', 'matched_transaction_id' => $best->id, 'match_score' => $best->match_score]);
        }
    }

    private function event(BankStatementRow $row, string $action, ?int $transactionId, ?string $reason, array $old, array $new): void
    {
        BankReconciliationEvent::create([
            'company_id' => $row->company_id, 'bank_statement_row_id' => $row->id,
            'financial_account_transaction_id' => $transactionId, 'action' => $action,
            'reason' => $reason, 'old_values' => $old, 'new_values' => $new,
            'user_id' => Auth::id(), 'created_at' => now(),
        ]);
    }

    private function detectDelimiter(string $header): string
    {
        $counts = [',' => substr_count($header, ','), ';' => substr_count($header, ';'), "\t" => substr_count($header, "\t")];
        arsort($counts);
        return (string) array_key_first($counts);
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'Y/m/d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) return $date->toDateString();
            } catch (\Throwable) {}
        }
        try { return CarbonImmutable::parse($value)->toDateString(); } catch (\Throwable) { return null; }
    }

    private function parseAmount(array $record, array $mapping): float
    {
        if (! empty($mapping['amount'])) return round((float) $this->number($record[$mapping['amount']] ?? ''), 2);
        $credit = (float) ($this->number($record[$mapping['credit'] ?? ''] ?? '') ?? 0);
        $debit = (float) ($this->number($record[$mapping['debit'] ?? ''] ?? '') ?? 0);
        return round($credit - $debit, 2);
    }

    private function number(mixed $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $value = preg_replace('/[^0-9,.-]/', '', $value);
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(['.', ','], ['', '.'], $value)
                : str_replace(',', '', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }
        return is_numeric($value) ? (float) $value : null;
    }

    private function signedAmount(FinancialAccountTransaction $transaction): float
    {
        return in_array($transaction->type, ['inflow', 'transfer_in', 'adjustment_in', 'refund_in'], true)
            ? round((float) $transaction->amount, 2) : -round((float) $transaction->amount, 2);
    }
}
