<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\BankReconciliationEvent;
use App\Models\BankStatement;
use App\Models\BankStatementMatch;
use App\Models\BankStatementRow;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\Support\Money;
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

    public function import(FinancialAccount $account, UploadedFile $file, array $mapping, string $delimiter = 'auto', array $options = []): BankStatement
    {
        abort_unless((int) $account->company_id === (int) Auth::user()->company_id, 404);
        if ($account->type !== 'bank') {
            throw ValidationException::withMessages(['financial_account_id' => 'Statements require a bank account.']);
        }
        $contents = $file->get();
        $hash = hash('sha256', $contents);
        $parsed = app(BankStatementCsvAdapter::class)->parse($contents, $mapping, $options, $delimiter);

        return DB::transaction(function () use ($account, $file, $hash, $parsed) {
            $this->lockAccount($account->id);
            $savedMapping = [...$parsed['mapping'], '_options' => $parsed['options']];
            if ($existing = BankStatement::query()->where('financial_account_id', $account->id)->where('file_hash', $hash)->lockForUpdate()->first()) {
                abort_unless($existing->column_mapping === $savedMapping, 409, 'This file was already staged with a different mapping or format. Review the original import.');

                return $this->show($existing);
            }
            $fingerprints = [];
            foreach ($parsed['rows'] as $r) {
                $fingerprints[] = $this->fingerprint($account->id, $r['date'], $r['amount'], $r['reference'], $r['description']);
            }
            $known = BankStatementRow::query()->whereHas('statement', fn ($q) => $q->where('financial_account_id', $account->id))->whereIn('source_fingerprint', array_unique($fingerprints))->orderBy('id')->get(['id', 'source_fingerprint'])->pluck('id', 'source_fingerprint')->all();
            $legacy = BankStatementRow::query()->whereNull('source_fingerprint')->whereHas('statement', fn ($q) => $q->where('financial_account_id', $account->id))->whereDate('transaction_date', '>=', $parsed['period_from'])->whereDate('transaction_date', '<=', $parsed['period_to'])->orderBy('id')->limit(10001)->get(['id', 'transaction_date', 'amount', 'reference_number', 'description']);
            if ($legacy->count() > 10000) {
                throw ValidationException::withMessages(['file' => 'Narrow this import date range to review duplicate evidence against legacy statement rows.']);
            }
            foreach ($legacy as $r) {
                $known[$this->fingerprint($account->id, $r->transaction_date->toDateString(), $r->amount, $r->reference_number, $r->description)] ??= $r->id;
            }
            $statement = BankStatement::create(['company_id' => $account->company_id, 'financial_account_id' => $account->id, 'file_name' => basename($file->getClientOriginalName()), 'file_hash' => $hash,
                'column_mapping' => $savedMapping, 'imported_by' => Auth::id(), 'period_from' => $parsed['period_from'], 'period_to' => $parsed['period_to'], 'opening_balance' => $parsed['opening_balance'], 'closing_balance' => $parsed['closing_balance'], 'reconciliation_status' => 'open']);
            $rows = [];
            foreach ($parsed['rows'] as $i => $r) {
                $rows[] = ['company_id' => $account->company_id, 'bank_statement_id' => $statement->id, 'transaction_date' => $r['date'], 'description' => $r['description'] ?: null, 'reference_number' => $r['reference'] ?: null,
                    'amount' => $r['amount'], 'statement_balance' => $r['balance'], 'status' => 'unmatched', 'raw_data' => json_encode($r['raw'], JSON_THROW_ON_ERROR), 'row_hash' => hash('sha256', $r['line'].'|'.$fingerprints[$i]), 'source_fingerprint' => $fingerprints[$i],
                    'duplicate_of_row_id' => $known[$fingerprints[$i]] ?? null, 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($rows, 500) as $batch) {
                DB::table('bank_statement_rows')->insert($batch);
            }
            if (app(DocumentService::class)->can('documents.upload') && app(DocumentService::class)->can('documents.update_metadata')) {
                app(DocumentEvidenceService::class)->store($file, 'Bank Document', [['bank-statement', $statement->id]]);
            }
            $this->refreshSuggestions($statement);

            return $this->show($statement->fresh());
        });
    }

    private function fingerprint(int $account, string $date, mixed $amount, ?string $reference, ?string $description): string
    {
        $text = fn ($s) => mb_strtolower(preg_replace('/\\s+/u', ' ', trim((string) $s)));

        return hash('sha256', json_encode([$account, $date, Money::normalize($amount), $text($reference), $text($description)], JSON_THROW_ON_ERROR));
    }

    private function lockAccount(int $id): FinancialAccount
    {
        DB::table('companies')->where('id', Auth::user()->company_id)->lockForUpdate()->first();

        return FinancialAccount::query()->lockForUpdate()->findOrFail($id);
    }

    private function editable(BankStatement $statement, ?BankStatementRow $row = null): void
    {
        if ($statement->reconciliation_status === 'completed') {
            throw ValidationException::withMessages(['statement' => 'This statement is completed. An authorized accountant must reopen it with a reason before changes.']);
        }
        app(AccountingService::class)->assertDateOpen(($row?->transaction_date ?? $statement->period_to)->toDateString());
    }

    public function show(BankStatement $statement): BankStatement
    {
        $page = $statement->rows()->with(['matchedTransaction.journalEntry:id,journal_number,status,source_module,source_type,source_id', 'journalEntry:id,journal_number,status,source_module,source_type,source_id', 'matches' => fn ($q) => $q->whereNull('released_at')->with('transaction.journalEntry')])->orderBy('transaction_date')->orderBy('id')->paginate(50);
        $statement->load('account:id,name,currency,opening_balance,opening_date,accounting_account_id');
        $statement->setRelation('rows', $page->getCollection());
        $statement->setAttribute('pagination', ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
        $statement->setAttribute('balance_control', app(BankReconciliationProof::class)->summary($statement));
        $statement->syncOriginalAttributes(['pagination', 'balance_control']);

        return $statement;
    }

    public function suggestions(BankStatementRow $row): array
    {
        $statement = $row->statement;

        return FinancialAccountTransaction::query()->with('journalEntry:id,journal_number,status')
            ->where('financial_account_id', $statement->financial_account_id)
            ->where('status', 'posted')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bank_statement_matches as owners')->whereColumn('owners.active_transaction_id', 'financial_account_transactions.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bank_statement_rows as owners')->where('owners.status', 'reconciled')->whereColumn('owners.matched_transaction_id', 'financial_account_transactions.id'))
            ->whereBetween('transaction_date', [
                $row->transaction_date->copy()->subDays(5)->toDateString(),
                $row->transaction_date->copy()->addDays(5)->toDateString(),
            ])->whereRaw('amount <= ?', [abs((float) $row->amount)])->orderBy('transaction_date')->orderBy('id')->limit(200)->get()->map(function (FinancialAccountTransaction $transaction) use ($row) {
                $signed = $this->signedAmount($transaction);
                $amountScore = abs($signed - (float) $row->amount) < .005 ? 60 : 0;
                $days = abs($transaction->transaction_date->diffInDays($row->transaction_date));
                $dateScore = max(0, 30 - ($days * 6));
                $referenceScore = $row->reference_number && $transaction->reference_number
                    && str_contains(mb_strtolower($transaction->reference_number), mb_strtolower($row->reference_number)) ? 10 : 0;
                $transaction->setAttribute('match_score', $amountScore + $dateScore + $referenceScore);
                $transaction->setAttribute('signed_amount', $signed);
                $transaction->setAttribute('match_confidence', $amountScore === 60 && $referenceScore === 10 ? 'strong' : ($amountScore === 60 ? 'exact_amount' : 'partial_candidate'));
                $transaction->setAttribute('match_evidence', ['exact_amount' => $amountScore === 60, 'date_difference_days' => $days, 'reference_match' => $referenceScore === 10, 'needs_posting' => $transaction->journalEntry?->status !== 'posted']);

                return $transaction;
            })->filter(fn ($transaction) => $transaction->match_score >= 24)
            ->sortByDesc('match_score')->values()->take(10)->all();
    }

    public function reconcile(BankStatementRow $row, FinancialAccountTransaction $transaction): BankStatementRow
    {
        return $this->reconcileMany($row, [$transaction->id]);
    }

    public function reconcileMany(BankStatementRow $row, array $transactionIds): BankStatementRow
    {
        $ids = array_values(array_unique(array_map('intval', $transactionIds)));
        sort($ids);
        if (! $ids || count($ids) > 100) {
            throw ValidationException::withMessages(['transaction_ids' => 'Select between one and 100 posted transactions.']);
        }
        $accountId = $row->statement->financial_account_id;
        $statementId = $row->bank_statement_id;

        return DB::transaction(function () use ($row, $ids, $accountId, $statementId) {
            $account = $this->lockAccount($accountId);
            $transactions = FinancialAccountTransaction::query()->with('journalEntry')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($transactions->count() === count($ids), 404);
            $statement = BankStatement::query()->lockForUpdate()->findOrFail($statementId);
            $locked = BankStatementRow::query()->lockForUpdate()->findOrFail($row->id);
            $this->editable($statement, $locked);
            if ((int) $statement->financial_account_id !== (int) $account->id) {
                throw ValidationException::withMessages(['statement' => 'The bank account changed. Refresh before matching.']);
            }
            $sum = '0.00';
            foreach ($transactions as $tx) {
                if ((int) $tx->financial_account_id !== (int) $account->id || $tx->status !== 'posted' || $tx->currency !== $account->currency || $tx->journalEntry?->status !== 'posted') {
                    throw ValidationException::withMessages(['transaction_ids' => 'Choose posted transactions with posted journals from the same bank account and currency.']);
                }
                app(AccountingService::class)->assertDateOpen($tx->transaction_date->toDateString());
                $sum = Money::add($sum, (string) $this->signedAmount($tx));
            }
            if (Money::compare($sum, $locked->amount) !== 0) {
                throw ValidationException::withMessages(['transaction_ids' => 'Selected signed transaction amounts must equal the statement row exactly.']);
            }
            $active = BankStatementMatch::query()->where('bank_statement_row_id', $locked->id)->whereNotNull('active_transaction_id')->lockForUpdate()->get();
            if ($locked->status === 'reconciled') {
                $owned = $active->pluck('financial_account_transaction_id')->sort()->values()->all();
                if ($owned === $ids) {
                    return $locked->fresh(['matches.transaction', 'statement.account']);
                }
                throw ValidationException::withMessages(['transaction_ids' => 'Unmatch the existing selection before choosing different transactions.']);
            }
            if ($locked->duplicate_of_row_id && ! $locked->duplicate_reviewed_at) {
                throw ValidationException::withMessages(['duplicate' => 'Review the overlapping-import evidence before matching this row.']);
            }
            foreach ($transactions as $tx) {
                $owner = BankStatementMatch::query()->where('active_transaction_id', $tx->id)->lockForUpdate()->first();
                $legacy = BankStatementRow::query()->where('matched_transaction_id', $tx->id)->where('status', 'reconciled')->whereKeyNot($locked->id)->lockForUpdate()->first();
                if ($owner || $legacy) {
                    throw ValidationException::withMessages(['transaction_ids' => 'A selected transaction is already reconciled. Every transaction has one active owner.']);
                }
            }
            $old = $locked->toArray();
            foreach ($transactions as $tx) {
                BankStatementMatch::create(['company_id' => $locked->company_id, 'bank_statement_row_id' => $locked->id, 'financial_account_transaction_id' => $tx->id, 'active_transaction_id' => $tx->id, 'signed_amount' => $this->signedAmount($tx), 'matched_by' => Auth::id(), 'matched_at' => now()]);
            }
            $first = $transactions->first();
            $locked->update(['status' => 'reconciled', 'matched_transaction_id' => $first->id, 'journal_entry_id' => $first->journal_entry_id, 'match_score' => 100, 'ignore_reason' => null, 'reviewed_by' => Auth::id(), 'reviewed_at' => now()]);
            $this->event($locked, 'reconciled', $first->id, null, $old, [...$locked->fresh()->toArray(), 'transaction_ids' => $ids]);
            app(BusinessEventService::class)->record('bank.transaction_reconciled', $locked, $locked->reference_number, ['financial_account_transaction_ids' => $ids], 'bank-row:'.$locked->id.':reconciled:'.hash('sha256', json_encode($ids)));

            return $locked->fresh(['matchedTransaction.journalEntry', 'matches.transaction.journalEntry', 'statement.account']);
        });
    }

    public function ignore(BankStatementRow $row, string $reason): BankStatementRow
    {
        return $this->releaseRow($row, $reason, 'ignored');
    }

    public function unmatch(BankStatementRow $row, string $reason): BankStatementRow
    {
        return $this->releaseRow($row, $reason, 'unmatched');
    }

    private function releaseRow(BankStatementRow $row, string $reason, string $status): BankStatementRow
    {
        $accountId = $row->statement->financial_account_id;
        $statementId = $row->bank_statement_id;

        return DB::transaction(function () use ($row, $reason, $status, $accountId, $statementId) {
            $this->lockAccount($accountId);
            $matches = BankStatementMatch::query()->where('bank_statement_row_id', $row->id)->whereNotNull('active_transaction_id')->lockForUpdate()->get();
            $ids = $matches->pluck('financial_account_transaction_id')->push($row->matched_transaction_id)->filter()->unique()->sort()->values();
            FinancialAccountTransaction::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $statement = BankStatement::query()->lockForUpdate()->findOrFail($statementId);
            $locked = BankStatementRow::query()->lockForUpdate()->findOrFail($row->id);
            $this->editable($statement, $locked);
            foreach ($matches as $match) {
                $match->update(['active_transaction_id' => null, 'released_at' => now(), 'released_by' => Auth::id(), 'release_reason' => trim($reason)]);
            }
            $old = $locked->toArray();
            $transactionId = $locked->matched_transaction_id;
            $locked->update(['status' => $status, 'matched_transaction_id' => null, 'journal_entry_id' => null, 'match_score' => null, 'ignore_reason' => $status === 'ignored' ? trim($reason) : null, 'reviewed_by' => Auth::id(), 'reviewed_at' => now()]);
            $this->event($locked, $status === 'ignored' ? 'ignored' : 'unmatched', $transactionId, $reason, $old, $locked->fresh()->toArray());

            return $locked->fresh(['matches', 'statement.account']);
        });
    }

    public function reviewDuplicate(BankStatementRow $row, string $reason): BankStatementRow
    {
        $accountId = $row->statement->financial_account_id;
        $statementId = $row->bank_statement_id;

        return DB::transaction(function () use ($row, $reason, $accountId, $statementId) {
            $this->lockAccount($accountId);
            $statement = BankStatement::query()->lockForUpdate()->findOrFail($statementId);
            $row = BankStatementRow::query()->lockForUpdate()->findOrFail($row->id);
            $this->editable($statement, $row);
            if (! $row->duplicate_of_row_id) {
                throw ValidationException::withMessages(['duplicate' => 'This row has no overlapping-import evidence.']);
            }
            $old = $row->toArray();
            $row->update(['duplicate_reviewed_by' => Auth::id(), 'duplicate_reviewed_at' => now(), 'duplicate_review_reason' => trim($reason)]);
            $this->event($row, 'distinct_bank_event_confirmed', null, $reason, $old, $row->fresh()->toArray());

            return $row->fresh();
        });
    }

    public function setBalances(BankStatement $statement, array $data): BankStatement
    {
        return DB::transaction(function () use ($statement, $data) {
            $this->lockAccount($statement->financial_account_id);
            $statement = BankStatement::query()->lockForUpdate()->findOrFail($statement->id);
            $this->editable($statement);
            $from = $data['period_from'];
            $to = $data['period_to'];
            $evidence = app(BankReconciliationProof::class)->bankEvidence($statement);
            if ($evidence !== null && (Money::compare($data['opening_balance'], $evidence['opening']) !== 0 || Money::compare($data['closing_balance'], $evidence['closing']) !== 0)) {
                throw ValidationException::withMessages(['balances' => 'Imported bank balances are source evidence. Review the original CSV and its format instead of changing one side to conceal a difference.']);
            }
            if ($statement->rows()->where(fn ($q) => $q->whereDate('transaction_date', '<', $from)->orWhereDate('transaction_date', '>', $to))->exists()) {
                throw ValidationException::withMessages(['period' => 'Statement dates must contain every staged movement.']);
            }
            app(AccountingService::class)->assertDateOpen($to);
            $old = $statement->only(['opening_balance', 'closing_balance', 'period_from', 'period_to']);
            $statement->update(['opening_balance' => Money::normalize($data['opening_balance']), 'closing_balance' => Money::normalize($data['closing_balance']), 'period_from' => $from, 'period_to' => $to]);
            $this->statementAudit('bank.statement_balances_reviewed', $statement, $old, $data['reason']);

            return $this->show($statement->fresh());
        });
    }

    public function complete(BankStatement $statement): BankStatement
    {
        abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'bank_reconciliation.complete'), 403);

        return DB::transaction(function () use ($statement) {
            $this->lockAccount($statement->financial_account_id);
            $statement = BankStatement::query()->lockForUpdate()->findOrFail($statement->id);
            if ($statement->reconciliation_status === 'completed') {
                return $this->show($statement);
            }
            $this->editable($statement);
            $proof = app(BankReconciliationProof::class)->summary($statement);
            if (! $proof['ready_to_complete']) {
                throw ValidationException::withMessages(['statement' => 'Reconciliation is blocked: '.collect($proof['checks'])->where('passed', false)->pluck('label')->implode('; ')]);
            }
            $proof += ['checked_by' => Auth::id(), 'checked_at' => now()->toIso8601String(), 'match_watermark' => BankStatementMatch::query()->max('id')];
            $proof['fingerprint'] = hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR));
            $previousProof = $statement->reconciliation_proof;
            $statement->update(['reconciliation_status' => 'completed', 'reconciled_by' => Auth::id(), 'reconciled_at' => now(), 'reconciliation_proof' => $proof]);
            $this->statementAudit('bank.statement_completed', $statement, $previousProof, 'Balance proof confirmed.');

            return $this->show($statement->fresh());
        });
    }

    public function reopen(BankStatement $statement, string $reason): BankStatement
    {
        abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'bank_reconciliation.reopen'), 403);

        return DB::transaction(function () use ($statement, $reason) {
            $this->lockAccount($statement->financial_account_id);
            $statement = BankStatement::query()->lockForUpdate()->findOrFail($statement->id);
            if ($statement->reconciliation_status !== 'completed') {
                throw ValidationException::withMessages(['statement' => 'Only a completed statement can be reopened.']);
            }
            app(AccountingService::class)->assertDateOpen($statement->period_to->toDateString());
            $old = $statement->reconciliation_proof;
            $statement->update(['reconciliation_status' => 'open', 'reopened_by' => Auth::id(), 'reopened_at' => now(), 'reopen_reason' => trim($reason)]);
            $this->statementAudit('bank.statement_reopened', $statement, $old, $reason);

            return $this->show($statement->fresh());
        });
    }

    private function statementAudit(string $action, BankStatement $statement, ?array $old, string $reason): void
    {
        ActivityLog::create(['company_id' => $statement->company_id, 'user_id' => Auth::id(), 'action' => $action, 'entity' => 'BankStatement', 'entity_id' => $statement->id, 'description' => str_replace('.', ' ', $action), 'old_value' => $old, 'new_value' => ['reason' => $reason, 'status' => $statement->reconciliation_status, 'proof' => $statement->reconciliation_proof]]);
    }

    private function refreshSuggestions(BankStatement $statement): void
    {
        foreach ($statement->rows()->where('status', 'unmatched')->orderBy('id')->limit(50)->get() as $row) {
            $best = collect($this->suggestions($row))->first(fn ($candidate) => Money::compare($candidate->signed_amount, $row->amount) === 0);
            if ($best) {
                $row->update(['status' => 'suggested', 'matched_transaction_id' => $best->id, 'match_score' => $best->match_score]);
            }
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

    private function signedAmount(FinancialAccountTransaction $transaction): string
    {
        return in_array($transaction->type,['inflow', 'transfer_in', 'adjustment_in', 'refund_in'],true) ? Money::normalize($transaction->amount) : Money::subtract(0,$transaction->amount);
    }
}
