<?php

namespace App\Services\Kosovo;

use App\Models\AccountingPeriod;
use App\Models\ActivityLog;
use App\Models\CorporateTaxReview;
use App\Models\InvoiceProfile;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Services\FxRevaluationService;
use App\Support\CompanyClock;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CorporateTaxReviewService
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function latest(AccountingPeriod $period): ?CorporateTaxReview
    {
        return CorporateTaxReview::query()->where('accounting_period_id', $period->id)->latest('id')->first();
    }

    public function preview(AccountingPeriod $period, array $data): array
    {
        if (CompanyCurrency::current() !== 'EUR' || ! InvoiceProfile::query()->where('country_code', 'XK')->exists()) {
            throw ValidationException::withMessages(['regime' => ['This worksheet requires a Kosovo entity with EUR books.']]);
        }
        $from = $period->starts_at->toDateString();
        $to = $period->ends_at->toDateString();
        $fiscalStart = $period->starts_at->toImmutable();
        if ($fiscalStart->day !== 1 || $fiscalStart->month !== 1
            || $period->ends_at->toDateString() !== $fiscalStart->addYear()->subDay()->toDateString()) {
            throw ValidationException::withMessages(['period' => ['Select a complete January–December tax year. Alternative reporting periods require separate review.']]);
        }
        $profit = $this->accounting->profitAndLoss($from, $to);
        $adjustments = [];
        $totalAdditions = $totalDeductions = '0.00';
        foreach ($data['adjustments'] ?? [] as $line) {
            $amount = Money::normalize($line['amount']);
            $journal = null;
            if ($journalId = $line['journal_entry_id'] ?? null) {
                $journal = JournalEntry::query()->whereIn('status', ['posted', 'reversed'])
                    ->whereDate('posting_date', '>=', $from)->whereDate('posting_date', '<=', $to)->findOrFail($journalId);
            }
            if ($line['direction'] === 'add') {
                $totalAdditions = Money::add($totalAdditions, $amount);
            } else {
                $totalDeductions = Money::add($totalDeductions, $amount);
            }
            $adjustments[] = ['direction' => $line['direction'], 'amount' => $amount, 'reason' => trim($line['reason']), 'journal_entry_id' => $journal?->id];
        }
        $taxable = Money::subtract(Money::add($profit['profit_before_tax'], $totalAdditions), $totalDeductions);
        $rate = Money::normalizeDecimal($data['rate_percent'], 4);
        $tax = Money::product([Money::maximum(0, $taxable), $rate, '0.01']);
        $snapshot = ['from' => $from, 'to' => $to, 'regime' => 'actual_income', 'policy_reference' => trim($data['policy_reference']),
            'rate_percent' => $rate, 'profit_before_tax' => $profit['profit_before_tax'], 'adjustments' => $adjustments,
            'additions' => $totalAdditions, 'deductions' => $totalDeductions, 'taxable_profit' => $taxable, 'current_tax' => $tax];

        return [...$snapshot, 'fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
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
                $review->update(['journal_entry_id' => $journal->id]);
            }
            ActivityLog::create([
                'company_id' => Auth::user()->company_id, 'user_id' => Auth::id(), 'action' => 'accounting.corporate_tax.review_approved',
                'entity' => 'CorporateTaxReview', 'entity_id' => $review->id, 'new_value' => $snapshot, 'description' => 'Internal actual-income worksheet approved.',
            ]);

            return $review;
        });
    }

    public function readyIfReviewed(AccountingPeriod $period): bool
    {
        $review = $this->latest($period);
        if (! $review) {
            return true;
        }
        $profit = $this->accounting->profitAndLoss($review->snapshot['from'], $review->snapshot['to']);

        return $review->snapshot['profit_before_tax'] === $profit['profit_before_tax'];
    }
}
