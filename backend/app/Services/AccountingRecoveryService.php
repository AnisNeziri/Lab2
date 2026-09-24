<?php

namespace App\Services;

use App\Models\{AccountingException, AccountingRecoveryAttempt, DailySale, Expense, FinancialAccountTransaction, Invoice, JournalEntry, LandedCost, StockMovement};
use Illuminate\Support\Facades\{Auth, DB};
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
        \App\Models\ActivityLog::create([
            'company_id' => Auth::user()->company_id, 'user_id' => Auth::id(),
            'action' => 'accounting.exception_retry.'.$attempt->status,
            'entity' => 'AccountingException', 'entity_id' => $attempt->accounting_exception_id,
            'description' => 'accounting exception retry '.$attempt->status,
            'new_value' => $attempt->only(['id', 'status', 'source_type', 'source_id', 'journal_entry_id', 'message']),
            'ip_address' => request()?->ip(),
        ]);
    }
}
