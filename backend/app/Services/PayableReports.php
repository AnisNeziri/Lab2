<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Support\CompanyClock;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Read-only supplier positions, built from existing bills and settlement audit. */
class PayableReports
{
    private function effectiveDate(string $table,string $date,string $timestamp):string
    {
        // Installation backup must also read a supported pre-migration database.
        // New rows use their company business date; old rows keep their legacy date.
        return \Illuminate\Support\Facades\Schema::hasColumn($table,$date)?"COALESCE($table.$date,DATE($table.$timestamp))":"DATE($table.$timestamp)";
    }

    private function allocations(string $asOf)
    {
        return DB::table('supplier_payment_allocation_requests')->where('supplier_payment_allocation_requests.company_id', Auth::user()->company_id)
            ->whereRaw($this->effectiveDate('supplier_payment_allocation_requests','allocation_date','allocated_at').' <= ?',[$asOf])->where(fn ($q) => $q->where('supplier_payment_allocation_requests.status', 'completed')->orWhere(fn ($r) => $r->where('supplier_payment_allocation_requests.status', 'reversed')->whereRaw($this->effectiveDate('supplier_payment_allocation_requests','reversal_date','reversed_at').' > ?', [$asOf])));
    }

    public function positions(string $asOf, array $filters = [])
    {
        $direct = ExpensePayment::query()->join('expenses as b', fn ($j) => $j->on('b.id', '=', 'expense_payments.expense_id')->where('b.company_id', Auth::user()->company_id))
            ->whereDate('payment_date', '<=', $asOf)->where(fn ($q) => $q->where('expense_payments.status', 'completed')->orWhereRaw($this->effectiveDate('expense_payments','reversal_date','reversed_at').' > ?',[$asOf]))
            ->selectRaw('expense_id,SUM(amount) amount,SUM(ROUND(amount*COALESCE(b.exchange_rate,1),2)) carrying')->groupBy('expense_id');
        $allocated = $this->allocations($asOf)->join('expenses as b', fn ($j) => $j->on('b.id', '=', 'supplier_payment_allocation_requests.expense_id')->where('b.company_id', Auth::user()->company_id))
            ->selectRaw('expense_id,SUM(amount) amount,SUM(ROUND(amount*COALESCE(b.exchange_rate,1),2)) carrying')->groupBy('expense_id');
        $credits = Expense::query()->where('document_type', 'credit_note')->whereDate('invoice_date', '<=', $asOf)
            ->where(fn ($q) => $q->where('status', 'posted')->orWhere(fn ($r) => $r->where('status', 'reversed')->whereRaw($this->effectiveDate('expenses','reversal_date','reversed_at').' > ?',[$asOf])))
            ->selectRaw('original_expense_id,SUM(gross_amount) amount,SUM(gross_amount_eur) carrying')->groupBy('original_expense_id');
        $q = Expense::query()->whereIn('document_type', ['purchase_invoice', 'opening_balance', 'fiscal_receipt', 'customs_document', 'other'])->whereDate('invoice_date', '<=', $asOf)
            ->where(fn ($q) => $q->where('expenses.status', 'posted')->orWhere(fn ($r) => $r->where('expenses.status', 'reversed')->whereRaw($this->effectiveDate('expenses','reversal_date','reversed_at').' > ?',[$asOf])))
            ->leftJoinSub($direct->toBase(), 'p', 'p.expense_id', '=', 'expenses.id')->leftJoinSub($allocated, 'a', 'a.expense_id', '=', 'expenses.id')->leftJoinSub($credits->toBase(), 'c', 'c.original_expense_id', '=', 'expenses.id')
            ->select('expenses.id', 'expenses.supplier_id', 'expenses.vendor_name', 'expenses.document_number', 'expenses.invoice_date', 'expenses.due_date', 'expenses.currency', 'expenses.gross_amount', 'expenses.gross_amount_eur', 'expenses.purchase_order_id')
            ->selectRaw('expenses.gross_amount-COALESCE(p.amount,0)-COALESCE(a.amount,0)-COALESCE(c.amount,0) outstanding,expenses.gross_amount_eur-COALESCE(p.carrying,0)-COALESCE(a.carrying,0)-COALESCE(c.carrying,0) base_outstanding');
        foreach (['supplier_id', 'currency'] as $key) {
            if (! empty($filters[$key])) {
                $q->where('expenses.'.$key, $filters[$key]);
            }
        }

        return DB::query()->fromSub($q->toBase(), 'ap')->whereRaw('(ABS(outstanding)>0.005 OR ABS(base_outstanding)>0.005)');
    }

    public function aging(string $asOf, array $filters = []): array
    {
        $date = CarbonImmutable::parse($asOf);
        $buckets = ['unknown_due' => 'due_date IS NULL', 'current' => 'DATE(due_date)>=?', 'days_1_30' => 'DATE(due_date)>=? AND DATE(due_date)<?',
            'days_31_60' => 'DATE(due_date)>=? AND DATE(due_date)<?', 'days_61_90' => 'DATE(due_date)>=? AND DATE(due_date)<?', 'days_90_plus' => 'DATE(due_date)<?'];
        $bindings = [[], [$asOf], [$date->subDays(30)->toDateString(), $asOf], [$date->subDays(60)->toDateString(), $date->subDays(30)->toDateString()], [$date->subDays(90)->toDateString(), $date->subDays(60)->toDateString()], [$date->subDays(90)->toDateString()]];
        $q = $this->positions($asOf, $filters);
        $aggregate = (clone $q)->selectRaw('COUNT(*) open_count,COALESCE(SUM(base_outstanding),0) total');
        $i = 0;
        foreach ($buckets as $key => $condition) {
            $aggregate->selectRaw("COALESCE(SUM(CASE WHEN $condition THEN base_outstanding ELSE 0 END),0) $key", $bindings[$i++]);
        }
        $summary = (array) $aggregate->first();
        foreach ($summary as $key => $value) {
            $summary[$key] = $key === 'open_count' ? (int) $value : Money::normalize($value);
        }
        $page = (clone $q)->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderBy('id')->paginate(50);

        return ['as_of' => $asOf, 'base_currency' => CompanyCurrency::current(), 'summary' => $summary, 'invoices' => $page,
            'top_suppliers' => (clone $q)->selectRaw('supplier_id,vendor_name,SUM(base_outstanding) outstanding')->groupBy('supplier_id', 'vendor_name')->orderByDesc('outstanding')->limit(10)->get(),
            'note' => 'Posted bills at carrying value, with dated payments, advance applications and credits. Unknown due dates are separate. Small carrying differences remain visible for investigation. Legacy expense currency conversion uses the existing EUR accounting foundation.'];
    }

    public function workbench(Supplier $supplier): array
    {
        abort_unless((int) $supplier->company_id === (int) Auth::user()->company_id, 404);
        $asOf = CompanyClock::today()->toDateString();
        $advances = PurchaseOrderPayment::query()->with('purchaseOrder:id,po_number,supplier_id')->withSum('allocations', 'amount')
            ->where('status', 'completed')->where(fn ($q) => $q->where('supplier_id', $supplier->id)->orWhereHas('purchaseOrder', fn ($p) => $p->where('supplier_id', $supplier->id)))
            ->orderBy('payment_date')->orderBy('id')->paginate(50, ['*'], 'advances_page');
        $advances->through(fn ($p) => ['id' => $p->id, 'purchase_order_id' => $p->purchase_order_id, 'po_number' => $p->purchaseOrder?->po_number, 'date' => $p->payment_date, 'currency' => $p->currency, 'amount' => $p->amount,
            'allocated' => Money::normalize($p->allocations_sum_amount ?? 0), 'remaining' => Money::subtract($p->amount, $p->allocations_sum_amount ?? 0), 'exchange_rate' => $p->exchange_rate, 'exchange_rate_date' => $p->exchange_rate_date, 'exchange_rate_source' => $p->exchange_rate_source]);

        return ['supplier' => $supplier->only(['id', 'name']), ...$this->aging($asOf, ['supplier_id' => $supplier->id]), 'advances' => $advances,
            'allocation_history' => DB::table('supplier_payment_allocation_requests as r')->join('expenses as e', fn ($j) => $j->on('e.id','=','r.expense_id')->where('e.company_id',$supplier->company_id))
                ->where('r.company_id',$supplier->company_id)->where('e.supplier_id',$supplier->id)->select('r.*','e.document_number','e.currency')->orderByDesc('r.id')->paginate(50,['*'],'history_page')];
    }
}
