<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use App\Support\CompanyClock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Read-only attribution of the existing customer ledger's AR effects. */
class ReceivablePositionQueries
{
    public function active(string $asOf, array $customerIds = [])
    {
        return CustomerDebtTransaction::query()->whereNull('reversed_transaction_id')
            ->whereDate('transaction_date', '<=', $asOf)
            ->whereDoesntHave('reversals', fn ($q) => $q->whereDate('transaction_date', '<=', $asOf))
            ->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds));
    }

    public function rows(?string $asOf = null, array $customerIds = [])
    {
        $asOf ??= CompanyClock::today()->toDateString();
        $base = $this->active($asOf, $customerIds);
        $legacy = '(balance_after=balance_before AND credit_after=credit_before)';
        $increase = "CASE WHEN $legacy THEN amount WHEN balance_after>balance_before THEN balance_after-balance_before ELSE 0 END";
        $decrease = "CASE WHEN $legacy THEN amount WHEN balance_before>balance_after THEN balance_before-balance_after ELSE 0 END";
        $debts = (clone $base)->whereIn('type', ['debt_added', 'positive_adjustment', 'opening_balance'])
            ->select('customer_debt_transactions.*')->selectRaw("$increase capacity, CASE WHEN $legacy THEN 1 ELSE 0 END legacy_snapshot");
        $payments = (clone $base)->whereIn('type', ['payment', 'negative_adjustment', 'return', 'cancellation'])
            ->selectRaw("customer_id, SUM($decrease) capacity")->groupBy('customer_id');
        $activeIds = fn () => (clone $base)->select('customer_debt_transactions.id');
        $allocations = DB::table('customer_settlement_allocations')->where('company_id', Auth::user()->company_id)
            ->whereDate('allocated_on', '<=', $asOf)->where(fn ($q) => $q->whereNull('released_on')->orWhereDate('released_on', '>', $asOf))
            ->whereIn('debt_transaction_id', $activeIds())->whereIn('settlement_transaction_id', $activeIds());
        $debtAllocated = (clone $allocations)->selectRaw('debt_transaction_id, SUM(amount) amount')->groupBy('debt_transaction_id');
        $customerAllocated = (clone $allocations)->selectRaw('customer_id, SUM(amount) amount')->groupBy('customer_id');
        $creditJson=DB::connection()->getQueryGrammar()->wrap('metadata->settled_debt_credit');
        $retired=CustomerDebtTransaction::query()->whereNotNull('reversed_transaction_id')->whereDate('transaction_date','<=',$asOf)
            ->when($customerIds,fn($q)=>$q->whereIn('customer_id',$customerIds))->selectRaw("customer_id,SUM(COALESCE(CAST($creditJson AS DECIMAL(18,2)),0)) amount")->groupBy('customer_id');
        $open = 'CASE WHEN d.capacity>COALESCE(a.amount,0) THEN d.capacity-COALESCE(a.amount,0) ELSE 0 END';
        $pool = 'CASE WHEN COALESCE(p.capacity,0)>COALESCE(x.amount,0)+COALESCE(retired.amount,0) THEN COALESCE(p.capacity,0)-COALESCE(x.amount,0)-COALESCE(retired.amount,0) ELSE 0 END';
        $ordered = DB::query()->fromSub($debts->toBase(), 'd')->leftJoinSub($debtAllocated, 'a', 'a.debt_transaction_id', '=', 'd.id')
            ->leftJoinSub($payments->toBase(), 'p', 'p.customer_id', '=', 'd.customer_id')->leftJoinSub($customerAllocated, 'x', 'x.customer_id', '=', 'd.customer_id')
            ->leftJoinSub($retired->toBase(),'retired','retired.customer_id','=','d.customer_id')
            ->selectRaw("d.*, COALESCE(a.amount,0) explicitly_allocated, $open open_amount, $pool fallback_pool,
                COALESCE(SUM($open) OVER (PARTITION BY d.customer_id ORDER BY CASE WHEN d.due_date IS NULL THEN 1 ELSE 0 END,d.due_date,d.transaction_date,d.id ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),0) prior_open");
        $available = 'CASE WHEN ranked.fallback_pool>ranked.prior_open THEN ranked.fallback_pool-ranked.prior_open ELSE 0 END';
        $outstanding = "CASE WHEN $available>=ranked.open_amount THEN 0 ELSE ranked.open_amount-($available) END";

        return DB::query()->fromSub($ordered, 'ranked')->selectRaw("ranked.*, $outstanding outstanding_amount, ranked.open_amount-($outstanding) automatically_allocated");
    }

    public function receiptHasReclassifiedFunding(CustomerDebtTransaction $payment):bool
    {
        return CustomerDebtTransaction::query()->where('customer_id',$payment->customer_id)->whereNotNull('reversed_transaction_id')->where('id','>',$payment->id)->where('metadata->settled_debt_credit','>',0)->exists();
    }

    public function obligations(Customer $customer, ?string $asOf = null): Collection
    {
        $rows = $this->rows($asOf, [$customer->id])->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderBy('transaction_date')->orderBy('id')->get();

        // Preserve CustomerCreditService's established model-shaped contract.
        return $rows->map(function ($r) {
            $m = (new CustomerDebtTransaction)->newFromBuilder((array) $r);
            foreach (['capacity', 'outstanding_amount', 'explicitly_allocated', 'automatically_allocated'] as $key) {
                $m->setAttribute($key, Money::normalize($r->{$key}));
            }

            return $m;
        });
    }

    public function agingForCustomers(array $ids, ?string $asOf = null): Collection
    {
        $asOf ??= CompanyClock::today()->toDateString();
        $date = CarbonImmutable::parse($asOf);
        $q = DB::query()->fromSub($this->rows($asOf, $ids), 'positions')->where('outstanding_amount', '>', 0)->groupBy('customer_id')
            ->selectRaw('customer_id,SUM(outstanding_amount) total,MIN(due_date) oldest_due,SUM(legacy_snapshot) legacy_rows')
            ->selectRaw('SUM(CASE WHEN due_date IS NULL THEN outstanding_amount ELSE 0 END) unknown_due')
            ->selectRaw('SUM(CASE WHEN DATE(due_date)>=? THEN outstanding_amount ELSE 0 END) current', [$asOf]);
        foreach ([['days_1_30', 30, 0], ['days_31_60', 60, 30], ['days_61_90', 90, 60]] as [$key,$lower,$upper]) {
            $q->selectRaw("SUM(CASE WHEN DATE(due_date)>=? AND DATE(due_date)<? THEN outstanding_amount ELSE 0 END) $key", [$date->subDays($lower)->toDateString(), $date->subDays($upper)->toDateString()]);
        }

        return $q->selectRaw('SUM(CASE WHEN DATE(due_date)<? THEN outstanding_amount ELSE 0 END) days_90_plus', [$date->subDays(90)->toDateString()])->get()->keyBy('customer_id');
    }
}
