<?php

namespace App\Services;

use App\Models\CustomerDebtTransaction;
use App\Models\DailySale;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Invoice;
use App\Models\InvoiceProfile;
use App\Models\LandedCostAccountingEntry;
use App\Models\PaymentTransaction;
use App\Models\PurchaseOrderPayment;
use App\Support\OpenXmlWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class FinanceService
{
    public function overview(string $from, string $to): array
    {
        $vat = $this->vatBooks($from, $to);
        $cash = $this->cashFlow($from, $to);
        $aging = $this->receivablesAging($to);
        $purchases = collect($vat['purchase_book']);
        $ordinaryExpense = round((float) $purchases->where('asset_treatment', 'ordinary')
            ->sum(fn (array $row) => (float) $row['net_amount_eur'] + (float) $row['non_deductible_vat_amount_eur']), 2);
        $investmentPurchases = round((float) $purchases->where('asset_treatment', 'investment')
            ->sum(fn (array $row) => (float) $row['net_amount_eur'] + (float) $row['non_deductible_vat_amount_eur']), 2);
        $salesRows = collect($vat['sales_book']);
        $invoiceSalesGross = round((float) $salesRows->sum('gross_amount'), 2);
        $invoiceSalesNet = round((float) $salesRows->sum('taxable_amount'), 2);
        $dailySales = round((float) DailySale::query()->finalized()
            ->whereBetween('sale_date', [$from, $to])->sum('total_amount'), 2);
        $missingProof = Expense::query()->whereIn('status', ['posted', 'reversed'])
            ->whereBetween('received_date', [$from, $to])->whereNull('proof_filename')->count();
        $unclassified = Expense::query()->whereIn('status', ['posted', 'reversed'])
            ->whereBetween('received_date', [$from, $to])->where('category', 'other')->count();
        $profile = InvoiceProfile::query()->first();
        $landedCostCogs = round((float) LandedCostAccountingEntry::query()
            ->whereDate('posted_at', '>=', $from)
            ->whereDate('posted_at', '<=', $to)
            ->sum('cogs_adjustment_amount'), 2);

        return [
            'summary' => [
                'invoice_sales' => $invoiceSalesGross,
                'invoice_sales_gross' => $invoiceSalesGross,
                'daily_sales' => $dailySales,
                // VAT collected is a tax liability, not company revenue.
                'sales_income' => $invoiceSalesNet,
                'sales_income_scope' => 'issued_invoices_only',
                'payments_received' => $cash['summary']['invoice_payments_received'],
                'total_expenses' => $ordinaryExpense,
                'investment_purchases' => $investmentPurchases,
                'cash_in' => $cash['summary']['cash_in'],
                'cash_out' => $cash['summary']['cash_out'],
                'net_cash_flow' => $cash['summary']['net_cash_flow'],
                'receivables_total' => $aging['summary']['total_outstanding'],
                'overdue_receivables' => $aging['summary']['overdue_total'],
                'vat_position' => $vat['summary']['net_vat'],
                'output_vat' => $vat['summary']['output_vat'],
                'deductible_input_vat' => $vat['summary']['deductible_input_vat'],
                'missing_tax_profile' => ! $profile,
                'unclassified_expenses_count' => $unclassified,
                'expenses_missing_proof_count' => $missingProof,
                'overdue_invoices_count' => $aging['summary']['overdue_count'],
                // This is a non-cash inventory-to-COGS recognition. It is
                // exposed separately and is not added to cash out or supplier
                // expenses, which would count the supplier document twice.
                'landed_cost_cogs_adjustments' => $landedCostCogs,
            ],
            'data_quality_warnings' => array_values(array_filter([
                ! $profile ? ['code' => 'missing_tax_profile', 'message' => 'Complete the company invoice/tax profile before relying on VAT preparation.'] : null,
                $missingProof > 0 ? ['code' => 'missing_expense_proof', 'message' => "{$missingProof} posted expense(s) have no supporting document."] : null,
                $unclassified > 0 ? ['code' => 'unclassified_expenses', 'message' => "{$unclassified} expense(s) use the Other category and need review."] : null,
                $dailySales > 0 ? ['code' => 'daily_sales_not_in_vat_book', 'message' => 'Daily Sales are shown separately and are not included in VAT output because their lines do not yet store immutable VAT snapshots.'] : null,
                $invoiceSalesGross > 0 && $dailySales > 0 ? ['code' => 'sales_sources_unreconciled', 'message' => 'Invoice sales and Daily Sales are separate sources and are not added together, preventing possible double counting.'] : null,
                ($cash['summary']['purchase_order_payments'] > 0 && $cash['summary']['expense_payments'] > 0)
                    ? ['code' => 'outflows_unreconciled', 'message' => 'Purchase-order and expense payments are separate records; review them for duplicates before using cash totals externally.'] : null,
                $landedCostCogs > 0
                    ? ['code' => 'landed_cost_cogs_adjustment', 'message' => 'Late landed costs for stock already sold are recognized as COGS in margin analytics; they are not an additional cash payment.'] : null,
            ])),
            'period' => compact('from', 'to'),
        ];
    }

    public function vatBooks(string $from, string $to): array
    {
        $sales = $this->salesRows($from, $to);
        $purchases = $this->purchaseRows($from, $to);
        $outputVat = round((float) $sales->sum('vat_amount') + (float) $purchases->sum('self_assessed_vat_amount_eur'), 2);
        $inputVat = round((float) $purchases->sum('deductible_vat_amount_eur'), 2);
        $dailySalesCount = DailySale::query()->finalized()->whereBetween('sale_date', [$from, $to])->count();

        return [
            'summary' => [
                'output_vat' => $outputVat,
                'deductible_input_vat' => $inputVat,
                'input_vat' => $inputVat,
                'net_vat' => round($outputVat - $inputVat, 2),
                'sales_taxable' => round((float) $sales->sum('taxable_amount'), 2),
                'purchase_net' => round((float) $purchases->sum('net_amount_eur'), 2),
                'non_deductible_input_vat' => round((float) $purchases->sum('non_deductible_vat_amount_eur'), 2),
                'preparation_status' => 'prepared_not_filed',
                'sales_scope' => 'issued_invoices_only',
                'daily_sales_without_vat_snapshots' => $dailySalesCount,
            ],
            'sales_book' => $sales->values()->all(),
            'purchase_book' => $purchases->values()->all(),
            'buckets' => $this->vatBuckets($sales, $purchases),
            'rb500' => $this->rb500((int) substr($to, 0, 4)),
            'warnings' => array_values(array_filter([
                $dailySalesCount > 0 ? ['code' => 'invoice_only_sales_book', 'message' => 'Sales Book is invoice-only because Daily Sales lack immutable VAT snapshots. Reconcile these sales before EDI filing.'] : null,
                ['code' => 'not_filed', 'message' => 'Prepared for review/export only. AIMS has not submitted these books to TAK EDI.'],
            ])),
            'period' => compact('from', 'to'),
        ];
    }

    public function cashFlow(string $from, string $to): array
    {
        $events = collect();
        $invoicePayments = PaymentTransaction::query()
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('payment_date', [$from, $to])
                    ->orWhere(fn ($reversal) => $reversal->whereNotNull('reversed_at')
                        ->whereDate('reversed_at', '>=', $from)->whereDate('reversed_at', '<=', $to));
            })->get();
        foreach ($invoicePayments as $payment) {
            if ($payment->payment_date?->betweenIncluded(CarbonImmutable::parse($from), CarbonImmutable::parse($to))) {
                $events->push($this->cashEvent($payment->payment_date->toDateString(), 'in', (float) $payment->amount, 'invoice_payment', $payment->reference_number ?? $payment->transaction_ref));
            }
            if ($payment->reversed_at && CarbonImmutable::parse($payment->reversed_at)->betweenIncluded(CarbonImmutable::parse($from), CarbonImmutable::parse($to))) {
                $events->push($this->cashEvent($payment->reversed_at->toDateString(), 'out', (float) $payment->amount, 'invoice_payment_reversal', $payment->reversal_reason));
            }
        }

        $dailySales = DailySale::query()->finalized()->whereBetween('sale_date', [$from, $to])
            ->whereDoesntHave('outboundDispatch.order', fn ($order) => $order->where('payment_type', '!=', 'cash'))->get();
        // Daily Sales can contain the same transactions later represented by
        // invoices. Until that linkage is immutable, disclose their receipts
        // separately instead of inflating the canonical cash-in KPI.

        $debtPayments = CustomerDebtTransaction::query()->where('type', 'payment')
            ->whereNull('payment_transaction_id')->whereBetween('transaction_date', [$from, $to])->get();
        foreach ($debtPayments as $payment) {
            $events->push($this->cashEvent($payment->transaction_date?->toDateString(), 'in', (float) $payment->amount, 'customer_debt_payment', $payment->reference_number));
        }

        $expensePayments = ExpensePayment::query()
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('payment_date', [$from, $to])
                    ->orWhere(fn ($reversal) => $reversal->whereNotNull('reversed_at')
                        ->whereDate('reversed_at', '>=', $from)->whereDate('reversed_at', '<=', $to));
            })->get();
        foreach ($expensePayments as $payment) {
            if ($payment->payment_date?->betweenIncluded(CarbonImmutable::parse($from), CarbonImmutable::parse($to))) {
                $events->push($this->cashEvent($payment->payment_date->toDateString(), 'out', (float) $payment->amount_eur, 'expense_payment', $payment->reference_number));
            }
            if ($payment->reversed_at && CarbonImmutable::parse($payment->reversed_at)->betweenIncluded(CarbonImmutable::parse($from), CarbonImmutable::parse($to))) {
                $events->push($this->cashEvent($payment->reversed_at->toDateString(), 'in', (float) $payment->amount_eur, 'expense_payment_reversal', $payment->reversal_reason));
            }
        }

        $purchasePayments = PurchaseOrderPayment::query()
            ->where('status', 'completed')
            ->whereBetween('payment_date', [$from, $to])
            ->get();
        // Purchase-order payments are shown separately, but are deliberately
        // excluded from canonical cash-out because the same payment may also
        // be recorded against its supplier expense and PO currencies vary.

        $timeline = $events->groupBy('date')->map(function (Collection $day, string $date) {
            $in = round((float) $day->sum('cash_in'), 2);
            $out = round((float) $day->sum('cash_out'), 2);

            return ['date' => $date, 'period' => $date, 'label' => $date, 'cash_in' => $in, 'cash_out' => $out, 'net' => round($in - $out, 2)];
        })->sortKeys()->values();
        $cashIn = round((float) $events->sum('cash_in'), 2);
        $cashOut = round((float) $events->sum('cash_out'), 2);

        return [
            'summary' => [
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'net_cash_flow' => round($cashIn - $cashOut, 2),
                'invoice_payments_received' => round((float) $events->where('source', 'invoice_payment')->sum('cash_in')
                    - (float) $events->where('source', 'invoice_payment_reversal')->sum('cash_out'), 2),
                'daily_sales_paid' => round((float) $dailySales->sum('paid_amount'), 2),
                'customer_debt_payments' => round((float) $debtPayments->sum('amount'), 2),
                'expense_payments' => round((float) $events->where('source', 'expense_payment')->sum('cash_out')
                    - (float) $events->where('source', 'expense_payment_reversal')->sum('cash_in'), 2),
                'purchase_order_payments' => round((float) $purchasePayments->sum('amount'), 2),
                'reconciled' => false,
            ],
            'periods' => $timeline->all(),
            'events' => $events->sortBy('date')->values()->all(),
            'warning' => 'Cash sources are operational and not bank-reconciled. Daily Sales receipts and purchase-order payments are disclosed separately but excluded from canonical cash totals because they cannot yet be reliably reconciled to invoice/expense payments.',
            'period' => compact('from', 'to'),
        ];
    }

    public function receivablesAging(string $asOf): array
    {
        $date = CarbonImmutable::parse($asOf)->startOfDay();
        $rows = collect();
        $invoices = Invoice::query()->with(['payments', 'creditNotes'])
            ->whereNull('daily_sale_id')
            ->where('document_type', 'invoice')->whereNotNull('issued_at')
            ->whereDate('invoice_date', '<=', $asOf)->get();
        foreach ($invoices as $invoice) {
            if ($invoice->voided_at && CarbonImmutable::parse($invoice->voided_at)->startOfDay()->lessThanOrEqualTo($date)) {
                continue;
            }
            $paidAsOf = $invoice->payments->sum(function (PaymentTransaction $payment) use ($date) {
                if (! $payment->payment_date || CarbonImmutable::parse($payment->payment_date)->startOfDay()->greaterThan($date)) {
                    return 0.0;
                }
                if ($payment->reversed_at && CarbonImmutable::parse($payment->reversed_at)->startOfDay()->lessThanOrEqualTo($date)) {
                    return 0.0;
                }

                return (float) $payment->amount;
            });
            $creditsAsOf = $invoice->creditNotes->sum(fn (Invoice $credit) => $credit->issued_at
                && CarbonImmutable::parse($credit->issued_at)->startOfDay()->lessThanOrEqualTo($date)
                && ! ($credit->voided_at && CarbonImmutable::parse($credit->voided_at)->startOfDay()->lessThanOrEqualTo($date))
                    ? (float) $credit->grand_total : 0.0);
            $outstanding = round(max(0, (float) $invoice->grand_total - $paidAsOf - $creditsAsOf), 2);
            if ($outstanding <= 0.005) {
                continue;
            }
            $due = $invoice->due_at ? CarbonImmutable::parse($invoice->due_at) : CarbonImmutable::parse($invoice->invoice_date);
            $days = $due->lessThan($date) ? $due->diffInDays($date) : 0;
            $bucket = match (true) {
                $days === 0 => 'current',
                $days <= 30 => 'days_1_30',
                $days <= 60 => 'days_31_60',
                $days <= 90 => 'days_61_90',
                default => 'days_90_plus',
            };
            $rows->push([
                'source' => 'invoice', 'invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id, 'customer_name' => $invoice->buyer_snapshot['legal_name'] ?? $invoice->customer_name,
                'invoice_date' => $invoice->invoice_date?->toDateString(), 'issued_at' => $invoice->issued_at?->toDateString(),
                'due_date' => $invoice->due_at?->toDateString(), 'days_overdue' => $days,
                'outstanding' => round($outstanding, 2), 'bucket' => $bucket,
            ]);
        }

        $ledgerBalances = CustomerDebtTransaction::query()->whereNull('invoice_id')
            ->whereDate('transaction_date', '<=', $asOf)->orderBy('transaction_date')->orderBy('id')->get()
            ->groupBy('customer_id')->map(function (Collection $transactions) {
                $increase = ['debt_added', 'positive_adjustment', 'opening_balance'];

                return round((float) $transactions->sum(fn ($row) => in_array($row->type, $increase, true) ? (float) $row->amount : -(float) $row->amount), 2);
            })->filter(fn (float $balance) => $balance > 0.005);
        $ledgerTotal = round((float) $ledgerBalances->sum(), 2);

        $buckets = ['current' => 0.0, 'days_1_30' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'days_90_plus' => 0.0];
        foreach ($rows as $row) {
            $buckets[$row['bucket']] = round($buckets[$row['bucket']] + (float) $row['outstanding'], 2);
        }
        $invoiceTotal = round((float) $rows->sum('outstanding'), 2);
        $overdue = round($buckets['days_1_30'] + $buckets['days_31_60'] + $buckets['days_61_90'] + $buckets['days_90_plus'], 2);

        return [
            'summary' => [
                'total_outstanding' => round($invoiceTotal + $ledgerTotal, 2),
                'invoice_outstanding' => $invoiceTotal,
                'separate_debt_ledger_outstanding' => $ledgerTotal,
                'overdue_total' => $overdue,
                'overdue_count' => $rows->where('days_overdue', '>', 0)->count(),
                'open_count' => $rows->count(),
            ],
            'aging_buckets' => $buckets,
            'receivables' => $rows->sortByDesc('days_overdue')->values()->all(),
            'note' => 'Separate Borxhet ledger balances are included only in the summary and are not duplicated as invoice rows.',
            'as_of' => $asOf,
        ];
    }

    public function vatWorkbook(string $from, string $to, string $locale = 'bilingual'): string
    {
        $books = $this->vatBooks($from, $to);
        $workbook = new OpenXmlWorkbook;
        $notice = [
            [OpenXmlWorkbook::cell('AIMS Kosovo VAT Books — PREPARED, NOT FILED', 'title')],
            ['Period', "{$from} to {$to}"],
            ['Scope', 'Sales book contains issued B2B invoices only. Daily Sales without immutable VAT snapshots are excluded and must be reconciled.'],
            ['EDI status', 'This workbook has NOT been submitted to TAK EDI. Review with an accountant and use the official TAK templates/portal.'],
            ['Generated at', now(config('app.timezone'))->toIso8601String()],
        ];
        $workbook->addSheet('Read Me', $notice);

        $salesHeaders = ['Date', 'Document No.', 'Type', 'Buyer', 'Buyer UIN', 'Buyer Fiscal No.', 'Buyer VAT No.', 'Taxable EUR', 'VAT EUR', 'Gross EUR', 'ATK Bucket', 'Legal reference'];
        $salesRows = [array_map(fn ($value) => OpenXmlWorkbook::cell($value, 'header'), $salesHeaders)];
        foreach ($books['sales_book'] as $row) {
            $salesRows[] = [
                $row['date'], $row['document_number'], $row['document_type'], $row['buyer_name'], $row['buyer_business_number'],
                $row['buyer_fiscal_number'], $row['buyer_vat_number'], OpenXmlWorkbook::cell($row['taxable_amount'], 'currency'),
                OpenXmlWorkbook::cell($row['vat_amount'], 'currency'), OpenXmlWorkbook::cell($row['gross_amount'], 'currency'),
                $row['bucket_code'], $row['legal_reference'],
            ];
        }
        $workbook->addSheet('Sales Book LSH', $salesRows);

        $purchaseHeaders = ['Received', 'Invoice date', 'Document No.', 'Type', 'Supplier', 'Supplier ID', 'Supplier VAT No.', 'Origin', 'Asset class', 'Net EUR', 'Supplier VAT EUR', 'Self-assessed VAT EUR', 'Deductible VAT EUR', 'Non-deductible VAT EUR', 'Gross payable EUR', 'ATK Bucket', 'Status/ref'];
        $purchaseRows = [array_map(fn ($value) => OpenXmlWorkbook::cell($value, 'header'), $purchaseHeaders)];
        foreach ($books['purchase_book'] as $row) {
            $purchaseRows[] = [
                $row['date'], $row['invoice_date'], $row['document_number'], $row['entry_type'], $row['supplier_name'],
                $row['supplier_identifier'], $row['supplier_vat_number'], $row['source_type'], $row['asset_treatment'],
                OpenXmlWorkbook::cell($row['net_amount_eur'], 'currency'), OpenXmlWorkbook::cell($row['vat_amount_eur'], 'currency'),
                OpenXmlWorkbook::cell($row['self_assessed_vat_amount_eur'], 'currency'), OpenXmlWorkbook::cell($row['deductible_vat_amount_eur'], 'currency'),
                OpenXmlWorkbook::cell($row['non_deductible_vat_amount_eur'], 'currency'), OpenXmlWorkbook::cell($row['gross_amount_eur'], 'currency'),
                $row['bucket_code'], $row['reference'],
            ];
        }
        $workbook->addSheet('Purchase Book LB', $purchaseRows);

        $summary = [[OpenXmlWorkbook::cell('VAT summary', 'header'), OpenXmlWorkbook::cell('EUR', 'header')]];
        foreach ([
            'Output VAT' => $books['summary']['output_vat'],
            'Deductible input VAT' => $books['summary']['deductible_input_vat'],
            'Estimated VAT payable / (credit)' => $books['summary']['net_vat'],
            'Non-deductible VAT' => $books['summary']['non_deductible_input_vat'],
        ] as $label => $amount) {
            $summary[] = [$label, OpenXmlWorkbook::cell($amount, 'currency')];
        }
        $workbook->addSheet('VAT Summary', $summary);

        $rbRows = [[OpenXmlWorkbook::cell('Supplier', 'header'), OpenXmlWorkbook::cell('Identifier', 'header'), OpenXmlWorkbook::cell('Annual purchases EUR', 'header'), OpenXmlWorkbook::cell('Review status', 'header')]];
        foreach ($books['rb500']['suppliers'] as $supplier) {
            $rbRows[] = [$supplier['supplier_name'], $supplier['supplier_identifier'], OpenXmlWorkbook::cell($supplier['annual_total'], 'currency'), $supplier['status']];
        }
        $workbook->addSheet('RB 500 Review', $rbRows);

        return $workbook->bytes();
    }

    private function salesRows(string $from, string $to): Collection
    {
        return Invoice::query()->with('items')->whereNotIn('status', ['draft', 'void'])
            ->whereBetween('invoice_date', [$from, $to])->orderBy('invoice_date')->orderBy('id')->get()
            ->flatMap(function (Invoice $invoice) {
                $sign = $invoice->document_type === 'credit_note' ? -1 : 1;
                $buyer = $invoice->buyer_snapshot ?? [];
                return $invoice->items
                    ->groupBy(fn ($item) => $item->tax_treatment.'|'.number_format((float) $item->vat_rate, 2, '.', ''))
                    ->map(function (Collection $items) use ($invoice, $buyer, $sign) {
                        $first = $items->first();
                        $rate = (float) $first->vat_rate;
                        $treatment = (string) $first->tax_treatment;
                        $taxable = round($sign * (float) $items->sum('taxable_amount'), 2);
                        $vat = round($sign * (float) $items->sum('vat_amount'), 2);

                        return [
                            'id' => $invoice->id, 'date' => $invoice->invoice_date?->toDateString(),
                            'document_number' => $invoice->invoice_number, 'invoice_number' => $invoice->invoice_number,
                            'document_type' => $invoice->document_type, 'buyer_name' => $buyer['legal_name'] ?? $invoice->customer_name,
                            'customer_name' => $buyer['legal_name'] ?? $invoice->customer_name,
                            'buyer_business_number' => $buyer['business_registration_number'] ?? null,
                            'buyer_fiscal_number' => $buyer['fiscal_number'] ?? null, 'buyer_vat_number' => $buyer['vat_number'] ?? null,
                            'taxable_amount' => $taxable, 'vat_amount' => $vat,
                            'gross_amount' => round($taxable + $vat, 2),
                            'vat_rate' => $rate, 'tax_treatment' => $treatment,
                            'bucket_code' => $this->salesBucket($treatment, $rate),
                            'legal_reference' => $items->pluck('tax_legal_reference')->filter()->unique()->implode('; '),
                        ];
                    })->values();
            });
    }

    private function purchaseRows(string $from, string $to): Collection
    {
        $expenses = Expense::query()->whereIn('status', ['posted', 'reversed'])
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('received_date', [$from, $to])
                    ->orWhere(function ($reversal) use ($from, $to) {
                        $reversal->whereNotNull('reversed_at')->whereDate('reversed_at', '>=', $from)->whereDate('reversed_at', '<=', $to);
                    });
            })->orderBy('received_date')->orderBy('id')->get();
        $rows = collect();
        foreach ($expenses as $expense) {
            $documentSign = $expense->document_type === 'credit_note' ? -1 : 1;
            if ($expense->received_date->betweenIncluded(CarbonImmutable::parse($from), CarbonImmutable::parse($to))) {
                $rows->push($this->purchaseRow($expense, $documentSign, 'document', $expense->received_date->toDateString(), $expense->original_document_number));
            }
            if ($expense->reversed_at && CarbonImmutable::parse($expense->reversed_at)->betweenIncluded(CarbonImmutable::parse($from), CarbonImmutable::parse($to))) {
                $rows->push($this->purchaseRow($expense, -$documentSign, 'reversal', $expense->reversed_at->toDateString(), $expense->reversal_reason));
            }
        }

        return $rows->sortBy([['date', 'asc'], ['id', 'asc']])->values();
    }

    private function purchaseRow(Expense $expense, int $sign, string $entryType, string $date, ?string $reference): array
    {
        return [
            'id' => $expense->id, 'date' => $date, 'invoice_date' => $expense->invoice_date?->toDateString(),
            'document_number' => $expense->document_number, 'entry_type' => $entryType,
            'supplier_name' => $expense->vendor_name,
            'supplier_identifier' => $expense->vendor_business_number ?: ($expense->vendor_fiscal_number ?: null),
            'supplier_vat_number' => $expense->vendor_vat_number,
            'source_type' => $expense->source_type, 'asset_treatment' => $expense->asset_treatment,
            'category' => $expense->category, 'tax_treatment' => $expense->vat_treatment, 'vat_rate' => (float) $expense->vat_rate,
            'net_amount_eur' => round($sign * (float) $expense->net_amount_eur, 2),
            'vat_amount_eur' => round($sign * (float) $expense->vat_amount_eur, 2),
            'self_assessed_vat_amount_eur' => round($sign * (float) $expense->self_assessed_vat_amount_eur, 2),
            'deductible_vat_amount_eur' => round($sign * (float) $expense->deductible_vat_amount_eur, 2),
            'non_deductible_vat_amount_eur' => round($sign * (float) $expense->non_deductible_vat_amount_eur, 2),
            'gross_amount_eur' => round($sign * (float) $expense->gross_amount_eur, 2),
            'bucket_code' => $this->purchaseBucket($expense), 'reference' => $reference,
        ];
    }

    private function vatBuckets(Collection $sales, Collection $purchases): array
    {
        $buckets = collect();
        foreach ($sales->groupBy('bucket_code') as $code => $rows) {
            $buckets->push(['book' => 'sales', 'code' => $code, 'label' => 'Sales Book '.$code, 'amount' => round((float) $rows->sum('taxable_amount'), 2), 'vat' => round((float) $rows->sum('vat_amount'), 2)]);
        }
        foreach ($purchases->groupBy('bucket_code') as $code => $rows) {
            $buckets->push(['book' => 'purchase', 'code' => $code, 'label' => 'Purchase Book '.$code, 'amount' => round((float) $rows->sum('net_amount_eur'), 2), 'vat' => round((float) $rows->sum('deductible_vat_amount_eur'), 2)]);
        }

        return $buckets->values()->all();
    }

    private function rb500(int $year): array
    {
        $rows = $this->purchaseRows("{$year}-01-01", "{$year}-12-31")
            ->groupBy(fn (array $row) => ($row['supplier_identifier'] ?: 'NAME:'.mb_strtolower($row['supplier_name'])))
            ->map(function (Collection $supplierRows, string $key) {
                $total = round((float) $supplierRows->sum('gross_amount_eur'), 2);
                $first = $supplierRows->first();

                return ['supplier_name' => $first['supplier_name'], 'supplier_identifier' => $first['supplier_identifier'] ?: $key, 'annual_total' => $total, 'status' => 'review'];
            })->filter(fn (array $row) => $row['annual_total'] >= 500)->sortByDesc('annual_total')->values();

        return [
            'year' => $year, 'threshold' => 500, 'suppliers' => $rows->all(),
            'status' => 'review',
            'note' => 'Threshold is aggregated per supplier/year. VAT-declarant exemption requires proof that all 12 monthly books were filed; AIMS cannot verify EDI filing, so accountant review remains required.',
        ];
    }

    private function salesBucket(string $treatment, float $rate): string
    {
        return match (true) {
            $treatment === 'reverse_charge' => '10b',
            $treatment === 'exempt' => '9',
            $treatment === 'zero_rated' => '10/11-review',
            $rate === 8.0 => '14/15',
            default => '12/13',
        };
    }

    private function purchaseBucket(Expense $expense): string
    {
        if ($expense->vat_treatment === 'reverse_charge') {
            return '65 (+ output 28)';
        }
        if (! $expense->input_vat_eligible && ((float) $expense->vat_amount > 0 || (float) $expense->self_assessed_vat_amount > 0)) {
            return $expense->asset_treatment === 'investment' ? '34' : '33';
        }
        if ($expense->document_type === 'credit_note') {
            return (float) $expense->vat_rate === 8.0 ? '55' : '53';
        }
        if ((float) $expense->vat_rate === 0.0) {
            return $expense->asset_treatment === 'investment' ? '32' : '31';
        }
        if ($expense->source_type === 'import') {
            return match (true) {
                $expense->asset_treatment === 'investment' && (float) $expense->vat_rate === 8.0 => '41',
                $expense->asset_treatment === 'investment' => '39',
                (float) $expense->vat_rate === 8.0 => '37',
                default => '35',
            };
        }

        return match (true) {
            $expense->asset_treatment === 'investment' && (float) $expense->vat_rate === 8.0 => '49',
            $expense->asset_treatment === 'investment' => '47',
            (float) $expense->vat_rate === 8.0 => '45',
            default => '43',
        };
    }

    private function cashEvent(?string $date, string $direction, float $amount, string $source, ?string $reference): array
    {
        return [
            'date' => $date, 'source' => $source, 'reference' => $reference,
            'cash_in' => $direction === 'in' ? round($amount, 2) : 0.0,
            'cash_out' => $direction === 'out' ? round($amount, 2) : 0.0,
        ];
    }

}
