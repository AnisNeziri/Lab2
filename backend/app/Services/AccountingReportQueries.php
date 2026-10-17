<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\JournalLine;
use App\Support\CompanyClock;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Read-only grouped projections of the existing posted General Ledger. */
class AccountingReportQueries
{
    public function accountActivity(AccountingAccount $account, ?string $from, ?string $to, int $perPage = 50): array
    {
        $to ??= CompanyClock::today()->toDateString();
        $totals = collect($this->trialBalance($from, $to)['rows'])->firstWhere('id', $account->id);
        $q = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.company_id', Auth::user()->company_id)->whereIn('j.status', ['posted', 'reversed'])
            ->where('l.accounting_account_id', $account->id)->when($from, fn ($q) => $q->where('j.posting_date', '>=', $from))
            ->where('j.posting_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString());
        $page = (clone $q)->select(['l.id', 'l.line_number', 'l.debit', 'l.credit', 'l.description', 'l.dimensions',
            'j.id as journal_id', 'j.journal_number', 'j.posting_date', 'j.reference_number', 'j.source_type', 'j.source_module'])
            ->orderBy('j.posting_date')->orderBy('j.id')->orderBy('l.line_number')->orderBy('l.id')->paginate($perPage);
        $first = $page->getCollection()->first();
        $balance = $totals['opening_balance'];
        if ($first) {
            $prior = (clone $q)->where(function ($q) use ($first) {
                $q->where('j.posting_date', '<', $first->posting_date)->orWhere(fn ($x) => $x->where('j.posting_date', $first->posting_date)->where('j.id', '<', $first->journal_id))
                    ->orWhere(fn ($x) => $x->where('j.posting_date', $first->posting_date)->where('j.id', $first->journal_id)->where('l.line_number', '<', $first->line_number));
            })->selectRaw('COALESCE(SUM(l.debit),0)-COALESCE(SUM(l.credit),0) amount')->value('amount');
            $balance = Money::add($balance, Money::normalize($prior));
        }
        $page->through(function ($line) use (&$balance) {
            $line->debit = Money::normalize($line->debit);
            $line->credit = Money::normalize($line->credit);
            $balance = Money::add($balance, Money::subtract($line->debit, $line->credit));
            $line->running_balance = $balance;
            $line->url = '/accounting?tab=journal&journal='.$line->journal_id;
            $line->source_label = AccountingJournalExperience::SOURCES[$line->source_type][1] ?? ($line->source_module === 'manual' ? 'Manual Journal' : 'Financial Journal');

            return $line;
        });

        return ['account' => $account, 'from' => $from, 'to' => $to, 'currency' => CompanyCurrency::forCompanyId(Auth::user()->company_id), 'totals' => $totals, 'lines' => $page];
    }

    public function trialBalance(?string $from, ?string $to): array
    {
        $to ??= CompanyClock::today()->toDateString();
        $q = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.company_id', Auth::user()->company_id)->whereIn('j.status', ['posted', 'reversed'])
            ->where('j.posting_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString())->groupBy('l.accounting_account_id');
        if ($from) {
            $q->selectRaw('l.accounting_account_id, SUM(CASE WHEN j.posting_date < ? THEN l.debit ELSE 0 END) od, SUM(CASE WHEN j.posting_date < ? THEN l.credit ELSE 0 END) oc, SUM(CASE WHEN j.posting_date >= ? THEN l.debit ELSE 0 END) d, SUM(CASE WHEN j.posting_date >= ? THEN l.credit ELSE 0 END) c', [$from, $from, $from, $from]);
        } else {
            $q->selectRaw('l.accounting_account_id, 0 od, 0 oc, SUM(l.debit) d, SUM(l.credit) c');
        }
        $sums = $q->get()->keyBy('accounting_account_id');
        $rows = AccountingAccount::query()->orderBy('code')->get()->map(function ($a) use ($sums) {
            $s = $sums->get($a->id);
            $opening = Money::subtract($s->od ?? 0, $s->oc ?? 0);
            $debit = Money::normalize($s->d ?? 0);
            $credit = Money::normalize($s->c ?? 0);
            $closing = Money::add($opening, Money::subtract($debit, $credit));

            return ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
                'parent_id' => $a->parent_id, 'statement_group' => $a->statement_group,
                'opening_balance' => $opening, 'opening_debit' => Money::maximum($opening, '0.00'),
                'opening_credit' => Money::maximum(Money::decimal(-Money::minor($opening)), '0.00'),
                'debit_movement' => $debit, 'credit_movement' => $credit, 'closing_balance' => $closing,
                'closing_debit' => Money::maximum($closing, '0.00'),
                'closing_credit' => Money::maximum(Money::decimal(-Money::minor($closing)), '0.00')];
        });
        $debit = Money::decimal($rows->sum(fn ($r) => Money::minor($r['debit_movement'])));
        $credit = Money::decimal($rows->sum(fn ($r) => Money::minor($r['credit_movement'])));

        return ['from' => $from, 'to' => $to, 'currency' => CompanyCurrency::forCompanyId(Auth::user()->company_id),
            'rows' => $rows, 'total_debit' => $debit, 'total_credit' => $credit,
            'total_opening_debit' => Money::decimal($rows->sum(fn ($r) => Money::minor($r['opening_debit']))),
            'total_opening_credit' => Money::decimal($rows->sum(fn ($r) => Money::minor($r['opening_credit']))),
            'total_closing_debit' => Money::decimal($rows->sum(fn ($r) => Money::minor($r['closing_debit']))),
            'total_closing_credit' => Money::decimal($rows->sum(fn ($r) => Money::minor($r['closing_credit']))),
            'balanced' => Money::compare($debit, $credit) === 0
                && $rows->sum(fn ($r) => Money::minor($r['closing_balance'])) === 0];
    }

    public function cashFlow(?string $from, ?string $to, array $cashIds): array
    {
        $to ??= CompanyClock::today()->toDateString();
        $base = fn () => DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.company_id', Auth::user()->company_id)->whereIn('j.status', ['posted', 'reversed'])
            ->when($from, fn ($q) => $q->where('j.posting_date', '>=', $from))->where('j.posting_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString());
        $cash = $base()->whereIn('l.accounting_account_id', $cashIds)->groupBy('j.id')
            ->selectRaw('j.id, SUM(l.debit)-SUM(l.credit) net')->havingRaw('SUM(l.debit)-SUM(l.credit) <> 0');
        $classes = $base()->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->whereNotIn('l.accounting_account_id', $cashIds)->groupBy('j.id')
            ->selectRaw("j.id, CASE WHEN COUNT(DISTINCT a.cash_flow_class)=1 AND SUM(CASE WHEN a.cash_flow_class IS NULL THEN 1 ELSE 0 END)=0 THEN MIN(a.cash_flow_class) ELSE 'unclassified' END flow_class");
        $groups = DB::query()->fromSub($cash, 'cash')->leftJoinSub($classes, 'classes', 'classes.id', '=', 'cash.id')
            ->selectRaw("COALESCE(classes.flow_class, 'unclassified') flow_class, SUM(cash.net) amount, COUNT(*) entries")
            ->groupBy('classes.flow_class')->get();
        $totals = ['operating' => 0, 'investing' => 0, 'financing' => 0, 'unclassified' => 0];
        $unclassified = 0;
        foreach ($groups as $g) {
            $key = array_key_exists($g->flow_class, $totals) ? $g->flow_class : 'unclassified';
            $totals[$key] += Money::minor($g->amount);
            if ($key === 'unclassified') {
                $unclassified += (int) $g->entries;
            }
        }
        $balance = fn ($date) => Money::normalize(JournalLine::query()->whereIn('accounting_account_id', $cashIds)
            ->whereHas('journalEntry', fn ($q) => $q->whereIn('status', ['posted', 'reversed'])->where('posting_date', '<', CarbonImmutable::parse($date)->addDay()->toDateString()))
            ->selectRaw('COALESCE(SUM(debit),0)-COALESCE(SUM(credit),0) net')->value('net') ?? 0);
        $opening = $from ? $balance(CarbonImmutable::parse($from)->subDay()->toDateString()) : '0.00';
        $closing = $balance($to);
        $net = array_sum($totals);
        $actual = Money::subtract($closing, $opening);

        return ['from' => $from, 'to' => $to, ...array_map(fn ($v) => Money::decimal($v), $totals),
            'net_cash_change' => Money::decimal($net), 'opening_cash' => $opening, 'closing_cash' => $closing,
            'actual_cash_change' => $actual, 'difference' => Money::subtract(Money::decimal($net), $actual),
            'reconciles' => $net === Money::minor($actual),
            'classification_complete' => $unclassified === 0, 'unclassified_entries' => $unclassified,
            'qualification' => 'Posted cash movements. Mixed or missing counter-account classifications remain unclassified; internal transfers net to zero.'];
    }
}
