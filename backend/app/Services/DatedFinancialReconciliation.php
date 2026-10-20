<?php

namespace App\Services;

use App\Models\AccountingPostingMapping;
use App\Models\CustomerDebtTransaction;
use App\Models\DailySale;
use App\Models\ExpensePayment;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\PurchaseOrderPayment;
use App\Models\StockMovement;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Independent dated operational evidence, compared to the existing posted GL. */
class DatedFinancialReconciliation
{
    private function effective($query, string $date, string $to)
    {
        return $query->whereDate($date, '<=', $to)->where(fn ($q) => $q->whereIn('status', ['posted', 'completed'])->orWhere(fn ($r) => $r->where('status', 'reversed')->whereRaw('COALESCE(reversal_date,DATE(reversed_at)) > ?', [$to])));
    }

    public function report(string $to): array
    {
        $company = Auth::user()->company_id;
        $currency = CompanyCurrency::current();
        $legacy = '(balance_before=balance_after AND credit_before=credit_after)';
        $arDelta = "CASE WHEN $legacy THEN CASE WHEN type IN ('debt_added','positive_adjustment','opening_balance') THEN amount WHEN type IN ('payment','negative_adjustment','return','cancellation') THEN -amount ELSE 0 END ELSE balance_after-balance_before END";
        $ledger = CustomerDebtTransaction::query()->whereDate('transaction_date', '<=', $to)->selectRaw("COALESCE(SUM($arDelta),0) ar,COALESCE(SUM(credit_after-credit_before),0) advance,SUM(CASE WHEN $legacy AND amount>0 THEN 1 ELSE 0 END) legacy")->first();
        $standalone = app(ReceivableReports::class)->positions($to)->where('source', 'invoice');
        $foreignAR = (clone $standalone)->where('currency', '!=', $currency)->count();
        $ar = Money::add($ledger->ar, (clone $standalone)->where('currency', $currency)->sum('outstanding'));
        $ap = Money::normalize(app(PayableReports::class)->positions($to)->sum('base_outstanding'));
        $apCurrencyUnsupported = $currency !== 'EUR' && DB::table('expenses')->where('company_id', $company)->whereIn('status', ['posted', 'reversed'])->whereDate('invoice_date', '<=', $to)->exists();
        $allocations = DB::table('supplier_payment_allocation_requests as r')->join('purchase_order_payments as p', fn ($j) => $j->on('p.id', '=', 'r.purchase_order_payment_id')->where('p.company_id', $company))
            ->where('r.company_id', $company)->whereRaw('COALESCE(r.allocation_date,DATE(r.allocated_at)) <= ?', [$to])->where(fn ($q) => $q->where('r.status', 'completed')->orWhere(fn ($r) => $r->where('r.status', 'reversed')->whereRaw('COALESCE(r.reversal_date,DATE(r.reversed_at)) > ?', [$to])))
            ->sum(DB::raw('ROUND(r.amount*COALESCE(p.exchange_rate,1),2)'));
        $advancePayments = $this->effective(PurchaseOrderPayment::query(), 'payment_date', $to);
        $supplierAdvances = Money::subtract((clone $advancePayments)->sum(DB::raw('ROUND(amount*COALESCE(exchange_rate,1),2)')), $allocations);

        // Roll the current carried valuation back using independent source
        // snapshots. Missing historical evidence blocks proof, never guesses.
        $future = StockMovement::query()->where('affects_company_quantity', true)->whereDate('occurred_at', '>', $to);
        $unknownMovement = (clone $future)->where(fn ($q) => $q->whereNull('inventory_value_before')->orWhereNull('inventory_value_after'))->count();
        $movementDelta = (clone $future)->selectRaw('product_id,SUM(ROUND(inventory_value_after,2)-ROUND(inventory_value_before,2)) delta')->groupBy('product_id');
        $landed = DB::table('landed_cost_accounting_entries as e')->join('landed_cost_allocations as a', fn ($j) => $j->on('a.id', '=', 'e.landed_cost_allocation_id')->where('a.company_id', $company))->where('e.company_id', $company);
        $landedDelta = (clone $landed)->selectRaw('e.product_id,SUM(CASE WHEN DATE(e.posted_at)>? THEN ROUND(a.inventory_value_after,2)-ROUND(a.inventory_value_before,2) ELSE 0 END)+SUM(CASE WHEN e.reversal_date>? THEN ROUND(e.reversal_inventory_value_after,2)-ROUND(e.reversal_inventory_value_before,2) ELSE 0 END) delta', [$to, $to])->groupBy('e.product_id');
        $unknownLanded = (clone $landed)->join('landed_costs as l', 'l.id', '=', 'e.landed_cost_id')->where('l.status', 'reversed')->whereDate('l.reversed_at', '>', $to)->whereNull('e.reversal_date')->count();
        $inventory = Product::query()->leftJoinSub($movementDelta->toBase(), 'm', 'm.product_id', '=', 'products.id')->leftJoinSub($landedDelta, 'l', 'l.product_id', '=', 'products.id')
            ->sum(DB::raw('ROUND(COALESCE(products.inventory_value,products.quantity*COALESCE(products.weighted_average_cost,products.purchase_price,0)),2)-COALESCE(m.delta,0)-COALESCE(l.delta,0)'));

        $cashTransactions = $this->effective(FinancialAccountTransaction::query(), 'transaction_date', $to);
        $cash = (clone $cashTransactions)->selectRaw("COALESCE(SUM(ROUND((CASE WHEN type IN ('inflow','transfer_in','adjustment_in','refund_in') THEN 1 ELSE -1 END)*amount*(CASE WHEN currency=? THEN 1 ELSE COALESCE(exchange_rate,0) END),2)),0) amount", [$currency])->value('amount');
        $cash = Money::add($cash, FinancialAccount::query()->where('currency', $currency)->whereDate('opening_date', '<=', $to)->sum('opening_balance'));
        $unknownBankOpening = FinancialAccount::query()->where('opening_balance', '!=', 0)->where(fn ($q) => $q->whereNull('opening_date')->orWhere('currency', '!=', $currency))->count();
        $sales = DailySale::query()->finalized()->whereDate('sale_date', '<=', $to)->whereDoesntHave('outboundDispatch', fn ($q) => $q->whereHas('order', fn ($o) => $o->where('payment_type', '!=', 'cash')))->sum('total_amount');
        $linkedSales = (clone $cashTransactions)->where('source_type', 'daily_sale')->whereIn('type', ['inflow', 'transfer_in'])->sum('amount');
        $invoicePayments = $this->effective(PaymentTransaction::query()->whereNull('financial_account_id'), 'payment_date', $to)->sum('amount');
        $customerPayments = CustomerDebtTransaction::query()->where('type', 'payment')->whereNull('financial_account_id')->whereNull('payment_transaction_id')->whereDate('transaction_date', '<=', $to)->whereDoesntHave('reversals', fn ($q) => $q->whereDate('transaction_date', '<=', $to))->sum('amount');
        $expensePayments = $this->effective(ExpensePayment::query()->whereNull('financial_account_id'), 'payment_date', $to)->sum('amount_eur');
        $poPayments = (clone $advancePayments)->whereNull('financial_account_id')->whereNull('onboarding_session_id')->sum('amount_eur');
        $cash = Money::subtract(Money::add($cash, $sales, $invoicePayments, $customerPayments), Money::add($linkedSales, $expensePayments, $poPayments));

        $tb = collect(app(AccountingService::class)->trialBalance(null, $to)['rows']);
        $mappings = AccountingPostingMapping::query()->pluck('accounting_account_id', 'mapping_key');
        $gl = function (string $key) use ($tb, $mappings) {
            $root = $mappings[$key] ?? null;
            if (! $root) {
                return null;
            }$ids = [$root];
            do {
                $prior = count($ids);
                $ids = array_values(array_unique([...$ids, ...$tb->whereIn('parent_id', $ids)->pluck('id')->all()]));
            } while (count($ids) !== $prior);

            return Money::decimal($tb->whereIn('id', $ids)->sum(fn ($r) => Money::minor($r['closing_balance'])));
        };
        $values = ['accounts_receivable' => $ar, 'customer_advances' => Money::normalize($ledger->advance), 'accounts_payable' => $ap, 'supplier_advances' => $supplierAdvances, 'inventory' => Money::normalize($inventory), 'cash_bank' => $cash];
        $issues = ['accounts_receivable' => $foreignAR ? ['Foreign customer invoices lack a supported historical base-currency valuation.'] : [],
            'customer_advances' => [], 'accounts_payable' => $apCurrencyUnsupported ? ['Supplier expense accounting currently uses the EUR base-currency foundation.'] : [], 'supplier_advances' => [],
            'inventory' => array_values(array_filter([$unknownMovement ? "$unknownMovement later inventory movements lack valuation snapshots." : null, $unknownLanded ? "$unknownLanded legacy landed-cost reversals lack per-product dated valuation evidence." : null])),
            'cash_bank' => $unknownBankOpening ? ['A nonzero financial-account opening lacks a supported date or base-currency quote.'] : []];
        if ((int) $ledger->legacy > 0) {
            $issues['accounts_receivable'][] = 'Legacy customer records lack position snapshots; verify opening and reversal evidence.';
            $issues['customer_advances'][] = 'Legacy customer records lack position snapshots; verify advance evidence.';
        }
        $rows = [];
        foreach ($values as $key => $operational) {
            $control = $key === 'cash_bank' ? ($gl('cash') !== null && $gl('bank') !== null ? Money::add($gl('cash'), $gl('bank')) : null) : $gl($key);
            if (in_array($key, ['customer_advances', 'accounts_payable'], true) && $control !== null) {
                $control = Money::subtract(0, $control);
            }
            if ($control === null) {
                $issues[$key][] = 'Configure the control-account mapping before proving this balance.';
            }
            $difference = $control === null ? null : Money::subtract($operational, $control);
            $ok = ! $issues[$key] && $difference !== null && Money::compare($difference, 0) === 0;
            $rows[] = ['control' => $key, 'operational_amount' => $operational, 'gl_amount' => $control, 'difference' => $difference, 'reconciled' => $ok, 'status' => $issues[$key] ? 'evidence_required' : ($ok ? 'reconciled' : 'mismatch'), 'evidence_issues' => $issues[$key],
                'drilldown_url' => '/accounting?tab='.match ($key) {
                    'accounts_receivable','customer_advances' => 'ar','accounts_payable','supplier_advances' => 'ap','cash_bank' => 'bank',default => 'reconciliation'
                }];
        }

        return ['as_of' => $to, 'currency' => $currency, 'rows' => $rows, 'all_reconciled' => collect($rows)->every(fn ($r) => $r['reconciled']), 'legacy_customer_snapshot_rows' => (int) $ledger->legacy,
            'basis' => 'Dated customer ledger, supplier bills/payments/advance applications, financial-account activity and independently recorded inventory valuation deltas. Legacy missing evidence is disclosed and blocks proof.'];
    }
}
