<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Dated AP and supplier-advance movements; no new balance authority. */
class SupplierStatementQueries
{
    public function events(Supplier $supplier, string $to)
    {
        $company = (int) Auth::user()->company_id;
        abort_unless((int) $supplier->company_id === $company, 404);
        $bills = Expense::query()->where('supplier_id', $supplier->id)->whereIn('status', ['posted', 'reversed'])->whereDate('invoice_date', '<=', $to);
        $sign = "CASE WHEN document_type='credit_note' THEN -1 ELSE 1 END";
        $invoice = (clone $bills)->selectRaw("'bill' source,id source_id,invoice_date date,10 sort_order,document_number reference,COALESCE(description,vendor_name) description,currency,gross_amount transaction_amount,($sign)*gross_amount_eur ap_delta,0 advance_delta");
        $billReversal = (clone $bills)->where('status', 'reversed')->whereRaw('COALESCE(reversal_date,DATE(reversed_at)) <= ?', [$to])
            ->selectRaw("'bill_reversal' source,id source_id,COALESCE(reversal_date,DATE(reversed_at)) date,90 sort_order,document_number reference,reversal_reason description,currency,-gross_amount transaction_amount,-($sign)*gross_amount_eur ap_delta,0 advance_delta");
        $payments = ExpensePayment::query()->join('expenses as b', fn ($j) => $j->on('b.id', '=', 'expense_payments.expense_id')->where('b.company_id', $company))->where('b.supplier_id', $supplier->id)->whereIn('expense_payments.status', ['completed', 'reversed'])->whereDate('payment_date', '<=', $to);
        $payment = (clone $payments)->selectRaw("'supplier_payment' source,expense_payments.id source_id,payment_date date,30 sort_order,reference_number reference,note description,b.currency,-amount transaction_amount,-ROUND(amount*COALESCE(b.exchange_rate,1),2) ap_delta,0 advance_delta");
        $paymentReversal = (clone $payments)->where('expense_payments.status', 'reversed')->whereRaw('COALESCE(expense_payments.reversal_date,DATE(expense_payments.reversed_at)) <= ?', [$to])
            ->selectRaw("'payment_reversal' source,expense_payments.id source_id,COALESCE(expense_payments.reversal_date,DATE(expense_payments.reversed_at)) date,90 sort_order,reference_number reference,expense_payments.reversal_reason description,b.currency,amount transaction_amount,ROUND(amount*COALESCE(b.exchange_rate,1),2) ap_delta,0 advance_delta");
        $advancePayments = PurchaseOrderPayment::query()->where(fn ($q) => $q->where('supplier_id', $supplier->id)->orWhereHas('purchaseOrder', fn ($p) => $p->where('supplier_id', $supplier->id)))->whereIn('status', ['completed', 'reversed'])->whereDate('payment_date', '<=', $to);
        $advance = (clone $advancePayments)->selectRaw("'supplier_advance' source,id source_id,payment_date date,20 sort_order,reference_number reference,note description,currency,amount transaction_amount,0 ap_delta,ROUND(amount*COALESCE(exchange_rate,1),2) advance_delta");
        $advanceReversal = (clone $advancePayments)->where('status', 'reversed')->whereRaw('COALESCE(reversal_date,DATE(reversed_at)) <= ?', [$to])
            ->selectRaw("'advance_reversal' source,id source_id,COALESCE(reversal_date,DATE(reversed_at)) date,90 sort_order,reference_number reference,reversal_reason description,currency,-amount transaction_amount,0 ap_delta,-ROUND(amount*COALESCE(exchange_rate,1),2) advance_delta");
        $allocations = DB::table('supplier_payment_allocation_requests as r')->join('expenses as b', fn ($j) => $j->on('b.id', '=', 'r.expense_id')->where('b.company_id', $company))
            ->join('purchase_order_payments as p', fn ($j) => $j->on('p.id', '=', 'r.purchase_order_payment_id')->where('p.company_id', $company))->where('r.company_id', $company)->where('b.supplier_id', $supplier->id)->whereIn('r.status', ['completed', 'reversed'])
            ->whereRaw('COALESCE(r.allocation_date,DATE(r.allocated_at)) <= ?', [$to]);
        $allocation = (clone $allocations)->selectRaw("'advance_applied' source,r.id source_id,COALESCE(r.allocation_date,DATE(r.allocated_at)) date,40 sort_order,b.document_number reference,r.reason description,b.currency,-r.amount transaction_amount,-ROUND(r.amount*COALESCE(b.exchange_rate,1),2) ap_delta,-ROUND(r.amount*COALESCE(p.exchange_rate,1),2) advance_delta");
        $allocationReversal = (clone $allocations)->where('r.status', 'reversed')->whereRaw('COALESCE(r.reversal_date,DATE(r.reversed_at)) <= ?', [$to])
            ->selectRaw("'allocation_reversal' source,r.id source_id,COALESCE(r.reversal_date,DATE(r.reversed_at)) date,90 sort_order,b.document_number reference,r.reversal_reason description,b.currency,r.amount transaction_amount,ROUND(r.amount*COALESCE(b.exchange_rate,1),2) ap_delta,ROUND(r.amount*COALESCE(p.exchange_rate,1),2) advance_delta");
        $events = $invoice->toBase()->unionAll($billReversal->toBase())->unionAll($payment->toBase())->unionAll($paymentReversal->toBase())->unionAll($advance->toBase())->unionAll($advanceReversal->toBase())->unionAll($allocation)->unionAll($allocationReversal);

        return DB::query()->fromSub($events, 'supplier_events');
    }

    public function statement(Supplier $supplier, string $from, string $to): array
    {
        $events = $this->events($supplier, $to);
        $opening = (clone $events)->whereDate('date', '<', $from)->selectRaw('COALESCE(SUM(ap_delta),0) payable,COALESCE(SUM(advance_delta),0) advance')->first();
        $closing = (clone $events)->selectRaw('COALESCE(SUM(ap_delta),0) payable,COALESCE(SUM(advance_delta),0) advance')->first();
        $rows = $this->running($supplier, $to)->whereDate('date', '>=', $from)->orderByRaw('DATE(date)')->orderBy('sort_order')->orderBy('source')->orderBy('source_id')->paginate(50);

        return ['supplier' => $supplier->only(['id', 'name']), 'from' => $from, 'to' => $to, 'currency' => CompanyCurrency::current(),
            'opening' => ['payable' => Money::normalize($opening->payable), 'advance' => Money::normalize($opening->advance)], 'closing' => ['payable' => Money::normalize($closing->payable), 'advance' => Money::normalize($closing->advance)], 'entries' => $rows,
            'note' => 'AP and advances remain separate. Running balances are base-currency carrying values; each source transaction currency is shown. Legacy expense conversion uses the existing EUR foundation.'];
    }

    public function running(Supplier $supplier, string $to)
    {
        $running = $this->events($supplier,$to)->select('supplier_events.*')->selectRaw('SUM(ap_delta) OVER (ORDER BY DATE(date),sort_order,source,source_id) payable_balance,SUM(advance_delta) OVER (ORDER BY DATE(date),sort_order,source,source_id) advance_balance');

        return DB::query()->fromSub($running,'running_supplier');
    }
}
