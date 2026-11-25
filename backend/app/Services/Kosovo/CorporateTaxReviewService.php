<?php

namespace App\Services\Kosovo;

use App\Models\AccountingPeriod;
use App\Models\AccountingPostingMapping;
use App\Models\ActivityLog;
use App\Models\CorporateTaxReview;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Services\FxRevaluationService;
use App\Support\CompanyClock;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class CorporateTaxReviewService
{
    private const FINGERPRINT_VERSION = 2;

    public function __construct(
        private readonly AccountingService $accounting,
        private readonly CorporateTaxDispositionService $dispositions,
        private readonly CorporateTaxEvidence $evidence,
    ) {}

    public function latest(AccountingPeriod $period): ?CorporateTaxReview
    {
        return CorporateTaxReview::query()->where('accounting_period_id', $period->id)->latest('id')->first();
    }

    public function preview(AccountingPeriod $period, array $data): array
    {
        $disposition = $this->dispositions->state($period);
        if ($disposition['state'] !== 'actual_income') {
            throw ValidationException::withMessages(['regime' => ['Record a current supported actual-income policy before calculating this worksheet.']]);
        }
        $from = $period->starts_at->toDateString();
        $to = $period->ends_at->toDateString();
        $profit = $this->accounting->profitAndLoss($from, $to);
        $adjustments = [];
        $totalAdditions = $totalDeductions = '0.00';
        foreach ($data['adjustments'] ?? [] as $line) {
            $amount = Money::normalize($line['amount']);
            $journal = null;
            if ($journalId = $line['journal_entry_id'] ?? null) {
                $journal = JournalEntry::query()->whereIn('status', ['posted', 'reversed'])
                    ->whereNotIn('id', CorporateTaxReview::query()->whereNotNull('journal_entry_id')->select('journal_entry_id'))
                    ->whereDate('posting_date', '>=', $from)->whereDate('posting_date', '<=', $to)->findOrFail($journalId);
            }
            if ($line['direction'] === 'add') {
                $totalAdditions = Money::add($totalAdditions, $amount);
            } else {
                $totalDeductions = Money::add($totalDeductions, $amount);
            }
            $adjustments[] = ['direction' => $line['direction'], 'amount' => $amount, 'reason' => trim($line['reason']), 'journal_entry_id' => $journal?->id];
        }
        usort($adjustments, fn ($left, $right) => [$left['direction'], $left['amount'], $left['journal_entry_id'], $left['reason']]
            <=> [$right['direction'], $right['amount'], $right['journal_entry_id'], $right['reason']]);
        $taxable = Money::subtract(Money::add($profit['profit_before_tax'], $totalAdditions), $totalDeductions);
        $rate = Money::normalizeDecimal($data['rate_percent'], 4);
        $tax = Money::product([Money::maximum(0, $taxable), $rate, '0.01']);
        $snapshot = ['fingerprint_version' => self::FINGERPRINT_VERSION, 'company_id' => (int) $period->company_id,
            'period_id' => (int) $period->id, 'accounting_basis' => 'dated_posted_accrual_ledger',
            'disposition_id' => $disposition['decision']->id, 'disposition_basis' => $disposition['decision']->basis,
            'source_digest' => $this->evidence->digest($period),
            'from' => $from, 'to' => $to, 'regime' => 'actual_income', 'policy_reference' => trim($data['policy_reference']),
            'rate_percent' => $rate, 'profit_before_tax' => $profit['profit_before_tax'], 'adjustments' => $adjustments,
            'additions' => $totalAdditions, 'deductions' => $totalDeductions, 'taxable_profit' => $taxable, 'current_tax' => $tax];

        return [...$snapshot, 'fingerprint' => CorporateTaxEvidence::hash($snapshot)];
    }

    public function approve(AccountingPeriod $period, array $data): CorporateTaxReview
    {
        return DB::transaction(function () use ($period, $data) {
            DB::table('companies')->where('id', Auth::user()->company_id)->lockForUpdate()->first();
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $preview = $this->preview($period, $data);
            if (! hash_equals($preview['fingerprint'], $data['fingerprint'])) {
                throw ValidationException::withMessages(['adjustments' => ['The ledger or adjustments changed. Run a fresh calculation before approval.']]);
            }
            $previous = $this->latest($period);
            if (! $this->taxJournalsReconcile($period)
                || $this->postedTax($period) !== Money::normalize($previous?->snapshot['current_tax'] ?? 0)) {
                throw ValidationException::withMessages(['journal' => ['Current-tax journals need accountant reconciliation before approval.']]);
            }
            if ($previous?->fingerprint === $preview['fingerprint']) {
                return $previous;
            }
            if ($period->ends_at->toDateString() > CompanyClock::today()->toDateString() || $period->status !== 'open'
                || ! app(FxRevaluationService::class)->readyForClose($period)) {
                throw ValidationException::withMessages(['period' => ['Use an ended, open fiscal year with current foreign monetary-balance review.']]);
            }
            $this->accounting->assertDateOpen($preview['to']);
            $snapshot = $preview;
            unset($snapshot['fingerprint']);
            $review = CorporateTaxReview::create([
                'accounting_period_id' => $period->id, 'fingerprint' => $preview['fingerprint'], 'snapshot' => $snapshot, 'approved_by' => Auth::id(),
            ]);
            $delta = Money::subtract($preview['current_tax'], $previous?->snapshot['current_tax'] ?? 0);
            if (Money::compare($delta, 0) !== 0) {
                $positive = Money::compare($delta, 0) > 0;
                $amount = $positive ? $delta : Money::subtract(0, $delta);
                $journal = $this->accounting->postMapped('corporate_tax', 'corporate_tax_review', $review->id, 'corporate-tax:'.$review->id,
                    $preview['to'], 'Current income tax '.$period->name, [
                        ['mapping' => 'corporate_tax_expense', $positive ? 'debit' : 'credit' => $amount],
                        ['mapping' => 'corporate_tax_payable', $positive ? 'credit' : 'debit' => $amount],
                    ], 'EUR');
                $snapshot['posting_account_ids'] = $this->postingAccountIds();
                $review->update(['journal_entry_id' => $journal->id, 'snapshot' => $snapshot]);
            }
            ActivityLog::create([
                'company_id' => Auth::user()->company_id, 'user_id' => Auth::id(), 'action' => 'accounting.corporate_tax.review_approved',
                'entity' => 'CorporateTaxReview', 'entity_id' => $review->id, 'new_value' => $snapshot, 'description' => 'Internal actual-income worksheet approved.',
            ]);

            return $review->fresh();
        });
    }

    public function readiness(AccountingPeriod $period): array
    {
        ['state' => $state, 'scope' => $scope, 'decision' => $decision] = $this->dispositions->state($period);
        $review = $scope === 'annual' ? $this->latest($period) : null;
        if (in_array($state, ['actual_income', 'inapplicable', 'alternative_reviewed'], true) && ! $this->taxJournalsReconcile($period)) {
            $state = 'journal_conflict';
        } elseif ($state === 'actual_income') {
            $state = $this->worksheetState($period, $review);
        } elseif (in_array($state, ['inapplicable', 'alternative_reviewed'], true)
            && $this->postedTax($period) !== ($state === 'inapplicable' ? '0.00' : $decision->policy['recorded_tax'])) {
            $state = 'journal_conflict';
        }

        return ['state' => $state, 'period_scope' => $scope, 'decision_id' => $decision?->id, 'review_id' => $review?->id,
            'ready' => in_array($state, ['not_annual', 'approved', 'inapplicable', 'alternative_reviewed'], true)];
    }

    private function worksheetState(AccountingPeriod $period, ?CorporateTaxReview $review): string
    {
        if (! $review) {
            return 'review_required';
        }
        if (($review->snapshot['fingerprint_version'] ?? null) !== self::FINGERPRINT_VERSION) {
            return 'legacy_review';
        }
        if ($review->snapshot['historical'] ?? false) {
            return 'stale_review';
        }
        try {
            $current = $this->preview($period, $review->snapshot);
        } catch (ModelNotFoundException|ValidationException) {
            return 'stale_review';
        }
        if (! hash_equals($review->fingerprint, $current['fingerprint'])) {
            return 'stale_review';
        }

        return $this->postedTax($period) === $review->snapshot['current_tax'] ? 'approved' : 'journal_conflict';
    }

    private function postedTax(AccountingPeriod $period): string
    {
        return $this->accounting->profitAndLoss($period->starts_at->toDateString(), $period->ends_at->toDateString())['income_tax'];
    }

    private function postingAccountIds(): array
    {
        return AccountingPostingMapping::query()->whereIn('mapping_key', ['corporate_tax_expense', 'corporate_tax_payable'])
            ->pluck('accounting_account_id', 'mapping_key')->map(fn ($id) => (int) $id)->all();
    }

    private function taxJournalsReconcile(AccountingPeriod $period): bool
    {
        $prior = '0.00';
        foreach (CorporateTaxReview::query()->where('accounting_period_id', $period->id)->orderBy('id')->get() as $review) {
            $amount = $review->snapshot['current_tax'];
            $delta = Money::subtract($amount, $prior);
            $prior = $amount;
            if ($delta === '0.00' && ! $review->journal_entry_id) {
                continue;
            }
            $journal = JournalEntry::query()->with('lines')->find($review->journal_entry_id);
            $accounts = $review->snapshot['posting_account_ids'] ?? $this->postingAccountIds();
            if ($delta === '0.00' || ! $journal || $journal->status !== 'posted'
                || $journal->source_module !== 'corporate_tax' || $journal->source_type !== 'corporate_tax_review'
                || (int) $journal->source_id !== $review->id || $journal->source_key !== 'corporate-tax:'.$review->id
                || $journal->posting_date->toDateString() !== $period->ends_at->toDateString() || $journal->currency !== 'EUR'
                || $journal->lines->count() !== 2 || count(array_unique($accounts)) !== 2) {
                return false;
            }
            foreach (['corporate_tax_expense' => $delta, 'corporate_tax_payable' => Money::subtract(0, $delta)] as $key => $balance) {
                $line = $journal->lines->firstWhere('accounting_account_id', $accounts[$key] ?? null);
                if (! $line || Money::subtract($line->debit, $line->credit) !== $balance) {
                    return false;
                }
            }
        }

        return true;
    }
}
