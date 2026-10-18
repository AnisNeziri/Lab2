<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Bounded read models over the two existing receivable source workflows. */
class ReceivableReports
{
    private function invoices(string $asOf)
    {
        return Invoice::query()->whereNull('daily_sale_id')->where('document_type', 'invoice')
            ->whereNotNull('issued_at')->whereDate('invoice_date', '<=', $asOf)
            // A documented ledger debt owns its balance; never count its invoice twice.
            ->whereDoesntHave('debtTransactions');
    }

    public function positions(string $asOf, array $filters = [])
    {
        $company = Auth::user()->company_id;
        $base = CompanyCurrency::current();
        $ids = empty($filters['customer_id']) ? [] : [(int) $filters['customer_id']];
        $ledger = DB::query()->fromSub(app(ReceivablePositionQueries::class)->rows($asOf, $ids), 'd')
            ->join('customers as c', fn ($j) => $j->on('c.id', '=', 'd.customer_id')->where('c.company_id', $company))
            ->leftJoin('daily_sales as s', fn ($j) => $j->on('s.id', '=', 'd.daily_sale_id')->where('s.company_id', $company))
            ->selectRaw("'customer_ledger' source,d.id source_id,d.customer_id,c.name customer_name,d.reference_number reference,d.transaction_date invoice_date,d.due_date,? currency,COALESCE(s.created_by,d.user_id) salesperson_id,d.outstanding_amount outstanding,d.legacy_snapshot", [$base]);
        $payments = PaymentTransaction::query()->whereDate('payment_date', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('reversed_at')->orWhereRaw('COALESCE(reversal_date,DATE(reversed_at)) > ?', [$asOf]))
            ->selectRaw('invoice_id,SUM(amount) paid')->groupBy('invoice_id');
        $credits = Invoice::query()->where('document_type', 'credit_note')->whereNotNull('issued_at')
            ->whereDate('invoice_date', '<=', $asOf)->where(fn ($q) => $q->whereNull('voided_at')->orWhereDate('voided_at', '>', $asOf))
            ->selectRaw('original_invoice_id,SUM(grand_total) credited')->groupBy('original_invoice_id');
        $invoices = $this->invoices($asOf)->leftJoinSub($payments->toBase(), 'p', 'p.invoice_id', '=', 'invoices.id')
            ->leftJoinSub($credits->toBase(), 'cr', 'cr.original_invoice_id', '=', 'invoices.id')
            ->where(fn ($q) => $q->whereNull('invoices.voided_at')->orWhereDate('invoices.voided_at', '>', $asOf))
            ->selectRaw("'invoice' source,invoices.id source_id,invoices.customer_id,invoices.customer_name,invoices.invoice_number reference,invoices.invoice_date,invoices.due_at due_date,invoices.currency,invoices.created_by salesperson_id,CASE WHEN invoices.grand_total>invoices.total_amount THEN invoices.grand_total ELSE invoices.total_amount END-COALESCE(p.paid,0)-COALESCE(cr.credited,0) outstanding,0 legacy_snapshot");
        $union = $ledger->unionAll($invoices->toBase());
        $q = DB::query()->fromSub($union, 'ar')->whereRaw('outstanding > 0.005');
        foreach (['customer_id', 'currency', 'salesperson_id'] as $key) {
            if (! empty($filters[$key])) {
                $q->where($key, $filters[$key]);
            }
        }

        return $q;
    }

    public function aging(string $asOf, array $filters = []): array
    {
        $date = CarbonImmutable::parse($asOf);
        $case = "CASE WHEN due_date IS NULL THEN 'unknown_due' WHEN DATE(due_date)>=? THEN 'current' WHEN DATE(due_date)>=? THEN 'days_1_30' WHEN DATE(due_date)>=? THEN 'days_31_60' WHEN DATE(due_date)>=? THEN 'days_61_90' ELSE 'days_90_plus' END";
        $positions = $this->positions($asOf, $filters)->select('ar.*')->selectRaw("$case bucket", [$asOf, $date->subDays(30)->toDateString(), $date->subDays(60)->toDateString(), $date->subDays(90)->toDateString()]);
        $query = fn () => DB::query()->fromSub(clone $positions, 'aged');
        // Keep each currency separate. Base summary is never a sum of unlike money.
        $groups = $query()->selectRaw('currency,bucket,SUM(outstanding) amount,COUNT(*) count,SUM(legacy_snapshot) legacy_rows')->groupBy('currency', 'bucket')->get();
        $base = CompanyCurrency::current();
        $selected = $filters['currency'] ?? $base;
        $buckets = array_fill_keys(['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus', 'unknown_due'], 0.0);
        foreach ($groups->where('currency', $selected) as $group) {
            $buckets[$group->bucket] = (float) Money::normalize($group->amount);
        }
        $overdue = array_sum(array_intersect_key($buckets, array_flip(['days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'])));
        $totals = $query()->where('currency', $selected)->selectRaw("SUM(CASE WHEN source='invoice' THEN outstanding ELSE 0 END) invoices,SUM(CASE WHEN source='customer_ledger' THEN outstanding ELSE 0 END) ledger,COUNT(*) count,SUM(CASE WHEN DATE(due_date)<? THEN 1 ELSE 0 END) overdue_count", [$asOf])->first();
        $page = $query()->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderBy('source')->orderBy('source_id')->paginate(50);
        $page->through(fn ($r) => [...(array) $r, 'invoice_id' => $r->source === 'invoice' ? $r->source_id : null,
            'invoice_number' => $r->reference, 'outstanding' => (float) Money::normalize($r->outstanding),
            'days_overdue' => $r->due_date === null ? null : max(0, (int) CarbonImmutable::parse($r->due_date)->diffInDays($date, false)),
            'href' => $r->source === 'invoice' ? '/invoices?invoice='.$r->source_id : '/customers?customer='.$r->customer_id]);

        return ['as_of' => $asOf, 'currency' => $selected, 'summary' => ['total_outstanding' => round(array_sum($buckets), 2),
            'invoice_outstanding' => (float) Money::normalize($totals->invoices ?? 0), 'separate_debt_ledger_outstanding' => (float) Money::normalize($totals->ledger ?? 0),
            'overdue_total' => round($overdue, 2), 'overdue_count' => (int) $totals->overdue_count, 'open_count' => (int) $totals->count,
            'unknown_due' => $buckets['unknown_due'], 'overdue_90_plus' => $buckets['days_90_plus']],
            'aging_buckets' => $buckets, 'currency_totals' => $groups, 'receivables' => $page->items(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'top_overdue_customers' => $query()->where('currency', $selected)->whereNotNull('due_date')->whereDate('due_date', '<', $asOf)
                ->selectRaw('customer_id,customer_name,SUM(outstanding) outstanding')->groupBy('customer_id', 'customer_name')->orderByDesc('outstanding')->limit(10)->get(),
            'note' => 'Posted source receivables; advances remain separate. Unknown due dates are not treated as current or overdue. Unassigned ledger settlements use oldest-known-due-first attribution. Legacy rows without position snapshots are flagged. Salesperson filter uses the recorded sale creator, or source creator when no sale exists. Currency totals are not converted.'];
    }

    /** Effective-date deltas, including reversals: no mutable current balance as opening. */
    public function statementEvents(Customer $customer, string $to, string $currency)
    {
        abort_unless((int) $customer->company_id === (int) Auth::user()->company_id, 404);
        $base = CompanyCurrency::current();
        $legacy = '(balance_before=balance_after AND credit_before=credit_after)';
        $delta = "CASE WHEN $legacy THEN CASE WHEN type IN ('debt_added','positive_adjustment','opening_balance') THEN amount ELSE -amount END ELSE balance_after-balance_before END";
        $ledger = $customer->debtTransactions()->whereDate('transaction_date', '<=', $to)
            ->selectRaw("'customer_ledger' source,id source_id,transaction_date date,type,reference_number reference,note description,? currency,$delta ar_delta,credit_after-credit_before advance_delta,CASE WHEN $legacy THEN 1 ELSE 0 END legacy_snapshot,reversed_transaction_id", [$base]);
        $standalone = $this->invoices($to)->where('invoices.customer_id', $customer->id);
        $invoice = (clone $standalone)->selectRaw("'invoice' source,invoices.id source_id,invoice_date date,'invoice' type,invoice_number reference,notes description,currency,CASE WHEN grand_total>total_amount THEN grand_total ELSE total_amount END ar_delta,0 advance_delta,0 legacy_snapshot,NULL reversed_transaction_id");
        $payment = PaymentTransaction::query()->whereIn('invoice_id', (clone $standalone)->select('invoices.id'))->whereDate('payment_date', '<=', $to)
            ->join('invoices as i', 'i.id', '=', 'payment_transactions.invoice_id')
            ->selectRaw("'invoice_payment' source,payment_transactions.id source_id,payment_date date,'payment' type,reference_number reference,note description,i.currency,-amount ar_delta,0 advance_delta,0 legacy_snapshot,NULL reversed_transaction_id");
        $paymentReversal = (clone $payment)->whereNotNull('reversed_at')->whereRaw('COALESCE(payment_transactions.reversal_date,DATE(payment_transactions.reversed_at)) <= ?', [$to]);
        $paymentReversal->getQuery()->columns = null;
        $paymentReversal->selectRaw("'invoice_payment_reversal' source,payment_transactions.id source_id,COALESCE(payment_transactions.reversal_date,DATE(payment_transactions.reversed_at)) date,'payment_reversal' type,reference_number reference,note description,i.currency,amount ar_delta,0 advance_delta,0 legacy_snapshot,NULL reversed_transaction_id");
        $credit = Invoice::query()->whereIn('original_invoice_id', (clone $standalone)->select('invoices.id'))->whereNotNull('issued_at')->whereDate('invoice_date', '<=', $to)
            ->selectRaw("'credit_note' source,id source_id,invoice_date date,'credit_note' type,invoice_number reference,credit_reason description,currency,-grand_total ar_delta,0 advance_delta,0 legacy_snapshot,NULL reversed_transaction_id");
        $void = (clone $standalone)->whereNotNull('voided_at')->whereDate('voided_at', '<=', $to)
            ->selectRaw("'invoice_void' source,invoices.id source_id,DATE(voided_at) date,'invoice_void' type,invoice_number reference,notes description,currency,-grand_total ar_delta,0 advance_delta,0 legacy_snapshot,NULL reversed_transaction_id");
        $union = $ledger->toBase()->unionAll($invoice->toBase())->unionAll($payment->toBase())->unionAll($paymentReversal->toBase())->unionAll($credit->toBase())->unionAll($void->toBase());

        return DB::query()->fromSub($union, 'events')->where('currency', $currency);
    }

    public function statement(Customer $customer, string $from, string $to, string $currency): array
    {
        $events = $this->statementEvents($customer, $to, $currency);
        $opening = (clone $events)->whereDate('date', '<', $from)->selectRaw('COALESCE(SUM(ar_delta),0) ar,COALESCE(SUM(advance_delta),0) advance')->first();
        $closing = (clone $events)->whereDate('date', '<=', $to)->selectRaw('COALESCE(SUM(ar_delta),0) ar,COALESCE(SUM(advance_delta),0) advance,SUM(legacy_snapshot) legacy_rows')->first();
        $running = (clone $events)->select('events.*')->selectRaw('SUM(ar_delta) OVER (ORDER BY DATE(date),source,source_id) ar_balance,SUM(advance_delta) OVER (ORDER BY DATE(date),source,source_id) advance_balance');
        $page = DB::query()->fromSub($running, 'running')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderByRaw('DATE(date)')->orderBy('source')->orderBy('source_id')->paginate(50);

        return ['customer' => $customer->only(['id', 'name', 'business_name']), 'from' => $from, 'to' => $to, 'currency' => $currency,
            'opening' => ['receivable' => Money::normalize($opening->ar), 'advance' => Money::normalize($opening->advance)],
            'closing' => ['receivable' => Money::normalize($closing->ar), 'advance' => Money::normalize($closing->advance)],
            'legacy_snapshot_rows' => (int) $closing->legacy_rows, 'entries' => $page,
            'note' => 'Receivables and customer advances are separate positions. Advance applied is the negative advance movement on a sale. Historical entries remain visible alongside their reversals. Legacy missing snapshots require accountant review.'];
    }
}
