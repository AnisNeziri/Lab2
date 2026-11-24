<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\FxRevaluation;
use App\Support\CompanyClock;
use App\Support\CompanyCurrency;
use App\Support\InvoiceExchange;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FxRevaluationService
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function preview(AccountingPeriod $period, array $quotes = []): array
    {
        $to = $period->ends_at->toDateString();
        $base = CompanyCurrency::current();
        $sources = $this->sources($to, $base);
        $issues = $base === 'EUR' || $sources->isEmpty() ? [] : ['This closing workflow requires EUR books.'];
        if ($sources->contains(fn ($item) => $item['carrying'] === null)) {
            $issues[] = 'Historical EUR evidence is missing for a foreign receivable or payable.';
        }
        if (FinancialAccount::query()->where('currency', '!=', $base)
            ->where(fn ($q) => $q->where('opening_balance', '!=', 0)->orWhereHas('transactions', fn ($q) => $q->whereDate('transaction_date', '<=', $to)))->exists()) {
            $issues[] = 'Foreign bank and cash balances need individual monetary-balance review before this close.';
        }
        $rates = [];
        $adjustments = ['ar' => '0.00', 'ap' => '0.00'];
        $rows = [];
        foreach ($sources->groupBy('currency')->sortKeys() as $currency => $positions) {
            if (! isset($quotes[$currency])) {
                $issues[] = "Provide the closing EUR rate and evidence for $currency.";
            } else {
                $rates[$currency] = InvoiceExchange::snapshot($quotes[$currency], $to);
            }
            foreach ($positions as $item) {
                $closing = isset($rates[$currency]) ? Money::multiply($item['nominal'], $rates[$currency]['rate']) : null;
                $delta = $closing !== null && $item['carrying'] !== null ? Money::subtract($closing, $item['carrying']) : null;
                if ($delta !== null) {
                    $adjustments[$item['side']] = Money::add($adjustments[$item['side']], $delta);
                }
                $rows[] = [...$item, 'closing' => $closing, 'adjustment' => $delta];
            }
        }
        $snapshot = ['date' => $to, 'rates' => $rates, 'items' => $rows, 'adjustments' => $adjustments];

        return ['period_id' => $period->id, ...$snapshot, 'issues' => $issues,
            'fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'last_approval' => FxRevaluation::query()->where('accounting_period_id', $period->id)->latest('id')
                ->first(['id', 'fingerprint', 'journal_entry_id', 'reversal_journal_id', 'reason'])];
    }

    public function approve(AccountingPeriod $period, array $data): FxRevaluation
    {
        return DB::transaction(function () use ($period, $data) {
            DB::table('companies')->where('id', Auth::user()->company_id)->lockForUpdate()->first();
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $preview = $this->preview($period, $data['rates'] ?? []);
            if ($preview['issues'] || ! hash_equals($preview['fingerprint'], $data['fingerprint'])) {
                throw ValidationException::withMessages(['rates' => $preview['issues'] ?: ['The positions or rates changed. Open a fresh preview before approving.']]);
            }
            $previous = FxRevaluation::query()->where('accounting_period_id', $period->id)->latest('id')->first();
            if ($previous?->fingerprint === $preview['fingerprint']) {
                return $previous;
            }
            $date = $period->ends_at->toDateString();
            if ($date > CompanyClock::today()->toDateString() || $period->status !== 'open') {
                throw ValidationException::withMessages(['period' => ['Use an ended, open accounting period for closing revaluation.']]);
            }
            $this->accounting->assertDateOpen($date);
            $snapshot = array_intersect_key($preview, array_flip(['date', 'rates', 'items', 'adjustments']));
            $entry = FxRevaluation::create([
                'accounting_period_id' => $period->id, 'accounting_date' => $date, 'fingerprint' => $preview['fingerprint'],
                'snapshot' => $snapshot, 'approved_by' => Auth::id(), 'reason' => $data['reason'],
            ]);
            $lines = [];
            foreach (['ar' => 'accounts_receivable', 'ap' => 'accounts_payable'] as $side => $control) {
                $delta = Money::subtract($preview['adjustments'][$side], $previous?->snapshot['adjustments'][$side] ?? 0);
                $signedDebit = $side === 'ar' ? $delta : Money::subtract(0, $delta);
                if (Money::compare($signedDebit, 0) > 0) {
                    $lines[] = ['mapping' => $control, 'debit' => $signedDebit];
                    $lines[] = ['mapping' => 'fx_unrealized_gain', 'credit' => $signedDebit];
                } elseif (Money::compare($signedDebit, 0) < 0) {
                    $amount = Money::subtract(0, $signedDebit);
                    $lines[] = ['mapping' => $control, 'credit' => $amount];
                    $lines[] = ['mapping' => 'fx_unrealized_loss', 'debit' => $amount];
                }
            }
            if ($lines) {
                $journal = $this->accounting->postMapped('fx_revaluation', 'fx_revaluation', $entry->id, 'fx-revaluation:'.$entry->id, $date, 'Closing currency revaluation '.$period->name, $lines, 'EUR');
                $nextDay = CarbonImmutable::parse($date)->addDay()->toDateString();
                $reversal = $this->accounting->reverse($journal, $nextDay, 'Reverse closing FX revaluation for the new period.');
                $entry->update(['journal_entry_id' => $journal->id, 'reversal_journal_id' => $reversal->id]);
            }
            ActivityLog::create([
                'company_id' => Auth::user()->company_id, 'user_id' => Auth::id(), 'action' => 'accounting.fx_revaluation.approved',
                'entity' => 'FxRevaluation', 'entity_id' => $entry->id, 'new_value' => ['fingerprint' => $entry->fingerprint, 'period' => $period->id, 'reason' => $entry->reason],
                'description' => 'Closing FX evidence approved.',
            ]);

            return $entry;
        });
    }

    public function currentAdjustments(string $date): array
    {
        return FxRevaluation::query()->where('accounting_date', $date)->latest('id')->first()?->snapshot['adjustments']
            ?? ['ar' => '0.00', 'ap' => '0.00'];
    }

    public function readyForClose(AccountingPeriod $period): bool
    {
        $latest = FxRevaluation::query()->where('accounting_period_id', $period->id)->latest('id')->first();
        $preview = $this->preview($period, $this->savedRates($period));

        return ! $preview['issues'] && ($latest ? $preview['fingerprint'] === $latest->fingerprint : $preview['items'] === []);
    }

    public function savedRates(AccountingPeriod $period): array
    {
        $review = FxRevaluation::query()->where('accounting_period_id', $period->id)->latest('id')->first();

        return collect($review?->snapshot['rates'] ?? [])->map(fn ($rate) => [
            'exchange_rate' => $rate['rate'], 'exchange_rate_date' => $rate['date'], 'exchange_rate_source' => $rate['source'],
        ])->all();
    }

    private function sources(string $date, string $base)
    {
        $ar = app(ReceivableReports::class)->positions($date)->where('source', 'invoice')->where('currency', '!=', $base)
            ->orderBy('source_id')->get(['source_id', 'reference', 'currency', 'outstanding', 'base_outstanding']);
        $ap = app(PayableReports::class)->positions($date)->where('currency', '!=', $base)->orderBy('id')->get();
        $verifiedBills = Expense::query()->whereIn('id', $ap->pluck('id'))->whereNotNull('exchange_rate_date')
            ->whereNotNull('exchange_rate_source')->where('exchange_rate', '>', 0)->pluck('id');
        $rows = $ar->map(fn ($item) => [
            'side' => 'ar', 'id' => (int) $item->source_id, 'reference' => $item->reference, 'currency' => $item->currency,
            'nominal' => Money::normalize($item->outstanding), 'carrying' => $item->base_outstanding === null ? null : Money::normalize($item->base_outstanding),
        ]);
        foreach ($ap as $item) {
            $rows->push(['side' => 'ap', 'id' => (int) $item->id, 'reference' => $item->document_number, 'currency' => $item->currency,
                'nominal' => Money::normalize($item->outstanding), 'carrying' => $verifiedBills->contains($item->id) ? Money::normalize($item->base_outstanding) : null]);
        }

        return $rows;
    }
}
