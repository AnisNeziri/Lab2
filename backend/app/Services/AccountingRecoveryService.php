<?php

namespace App\Services;

use App\Models\AccountingException;
use App\Models\AccountingRecoveryAttempt;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\DailySale;
use App\Models\Expense;
use App\Models\FinancialAccountTransaction;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\LandedCost;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountingRecoveryService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly OperationalAccountingService $operations,
    ) {}

    public function retry(AccountingException $exception, array $context = []): AccountingRecoveryAttempt
    {
        if ($exception->status !== 'open') {
            throw ValidationException::withMessages(['exception' => ['Only an open accounting exception can be retried.']]);
        }

        $details = $exception->details ?? [];
        $sourceType = $details['source_type'] ?? null;
        $sourceId = isset($details['source_id']) ? (int) $details['source_id'] : null;
        if (! $sourceType || ! $sourceId) {
            throw ValidationException::withMessages(['exception' => ['This exception is a control mismatch and cannot be repaired automatically. Use the drill-down and post a controlled correction.']]);
        }

        try {
            $journal = DB::transaction(fn () => $this->repost($sourceType, $sourceId, $context));
            if (! $journal) {
                throw ValidationException::withMessages(['exception' => ['The source is not eligible for an accounting posting. No data was changed.']]);
            }
            $attempt = AccountingRecoveryAttempt::create([
                'company_id' => Auth::user()->company_id, 'accounting_exception_id' => $exception->id,
                'attempted_by' => Auth::id(), 'status' => 'succeeded', 'source_type' => $sourceType,
                'source_id' => $sourceId, 'journal_entry_id' => $journal->id,
                'message' => 'Accounting posting recovered successfully.', 'context' => $context, 'attempted_at' => now(),
            ]);
            $this->accounting->resolveAccountingException($exception, 'Recovered by retry attempt #'.$attempt->id.'.');
            $this->audit($attempt);

            return $attempt->load(['journalEntry', 'user:id,name']);
        } catch (Throwable $error) {
            $attempt = AccountingRecoveryAttempt::create([
                'company_id' => Auth::user()->company_id, 'accounting_exception_id' => $exception->id,
                'attempted_by' => Auth::id(), 'status' => 'failed', 'source_type' => $sourceType,
                'source_id' => $sourceId, 'message' => $error->getMessage(), 'context' => $context, 'attempted_at' => now(),
            ]);
            $this->audit($attempt);
            throw $error;
        }
    }

    private function repost(string $type, int $id, array $context): ?JournalEntry
    {
        // A recovery may reconstruct a missing journal only for a finalized
        // source. A crafted/stale exception must never post a draft or a reversal.
        Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
        $class = match ($type) {
            'daily_sale' => DailySale::class, 'invoice' => Invoice::class,
            'stock_movement' => StockMovement::class, 'landed_cost' => LandedCost::class,
            'expense' => Expense::class, 'financial_account_transaction' => FinancialAccountTransaction::class,
            default => throw ValidationException::withMessages(['exception' => 'This source requires a correction in its operational module.']),
        };
        $source = $class::query()->lockForUpdate()->findOrFail($id);
        $eligible = match ($type) {
            'daily_sale' => $source->status === 'finalized',
            'invoice' => $source->document_type === 'invoice' && in_array($source->status, ['issued', 'partially_paid', 'paid'], true),
            'landed_cost', 'expense' => $source->status === 'posted',
            'financial_account_transaction' => $source->status === 'posted' && in_array($source->source_type, [null, 'manual'], true),
            'stock_movement' => (bool) $source->affects_company_quantity && $source->cost_total !== null,
        };
        if (! $eligible) {
            throw ValidationException::withMessages(['source' => 'Only a finalized source can recover its journal. Use the original document workflow for draft, reversed or source-owned payment records.']);
        }

        $prior = JournalEntry::query()->where('source_type', $type)->where('source_id', $id)->whereIn('status', ['posted', 'reversed'])->orderByDesc('id')->first();
        if ($prior?->status === 'posted') {
            return $prior;
        }
        if ($prior) {
            throw ValidationException::withMessages(['source' => 'This source has reversed accounting history. Use its operational correction workflow rather than recovering a second posting.']);
        }

        return match ($type) {
            'daily_sale' => $this->operations->postDailySale(DailySale::query()->findOrFail($id)),
            'invoice' => $this->operations->postInvoice(Invoice::query()->findOrFail($id)),
            'stock_movement' => $this->operations->postStockMovement(StockMovement::query()->findOrFail($id)),
            'landed_cost' => $this->operations->postLandedCost(LandedCost::query()->findOrFail($id)),
            'expense' => $this->accounting->postExpense(Expense::query()->findOrFail($id)),
            'financial_account_transaction' => $this->accounting->postFinancialTransaction(
                FinancialAccountTransaction::query()->with('account')->findOrFail($id),
                isset($context['counter_accounting_account_id']) ? (int) $context['counter_accounting_account_id'] : null,
            ),
            default => throw ValidationException::withMessages(['exception' => ["Automatic recovery is not supported for source type {$type}."]]),
        };
    }

    private function audit(AccountingRecoveryAttempt $attempt): void
    {
        ActivityLog::create([
            'company_id' => Auth::user()->company_id, 'user_id' => Auth::id(),
            'action' => 'accounting.exception_retry.'.$attempt->status,
            'entity' => 'AccountingException', 'entity_id' => $attempt->accounting_exception_id,
            'description' => 'accounting exception retry '.$attempt->status,
            'new_value' => $attempt->only(['id', 'status', 'source_type', 'source_id', 'journal_entry_id', 'message']),
            'ip_address' => request()?->ip(),
        ]);
    }
}
