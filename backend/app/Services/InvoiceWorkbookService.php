<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\OpenXmlWorkbook;

class InvoiceWorkbookService
{
    public function build(Invoice $invoice, string $locale = 'bilingual'): string
    {
        $invoice->loadMissing(['items', 'payments.user:id,name', 'originalInvoice:id,invoice_number']);
        $seller = $invoice->seller_snapshot ?? [];
        $buyer = $invoice->buyer_snapshot ?? [];
        $sign = $invoice->document_type === 'credit_note' ? -1 : 1;
        $workbook = new OpenXmlWorkbook;

        $summary = [
            [OpenXmlWorkbook::cell($this->label('export_copy', $locale), 'title')],
            [$this->label('field', $locale), $this->label('value', $locale)],
            [$this->label('document_type', $locale), $invoice->document_type],
            [$this->label('document_number', $locale), $invoice->invoice_number],
            [$this->label('status', $locale), $invoice->status],
            [$this->label('payment_status', $locale), $invoice->payment_status],
            [$this->label('invoice_date', $locale), $invoice->invoice_date?->toDateString()],
            [$this->label('supply_date', $locale), $invoice->supply_date?->toDateString()],
            [$this->label('due_date', $locale), $invoice->due_at?->toDateString()],
            [$this->label('currency', $locale), $invoice->currency],
            [$this->label('seller', $locale), $seller['legal_name'] ?? $seller['trade_name'] ?? ''],
            [$this->label('seller_business', $locale), $seller['business_registration_number'] ?? ''],
            [$this->label('seller_fiscal', $locale), $seller['fiscal_number'] ?? ''],
            [$this->label('seller_vat', $locale), $seller['vat_number'] ?? ''],
            [$this->label('buyer', $locale), $buyer['legal_name'] ?? $invoice->customer_name],
            [$this->label('buyer_business', $locale), $buyer['business_registration_number'] ?? ''],
            [$this->label('buyer_fiscal', $locale), $buyer['fiscal_number'] ?? ''],
            [$this->label('buyer_vat', $locale), $buyer['vat_number'] ?? ''],
            [$this->label('subtotal', $locale), OpenXmlWorkbook::cell($sign * (float) $invoice->subtotal, 'currency')],
            [$this->label('discount', $locale), OpenXmlWorkbook::cell($sign * (float) $invoice->discount_total, 'currency')],
            [$this->label('taxable', $locale), OpenXmlWorkbook::cell($sign * (float) $invoice->taxable_total, 'currency')],
            [$this->label('vat', $locale), OpenXmlWorkbook::cell($sign * (float) $invoice->vat_total, 'currency')],
            [$this->label('grand_total', $locale), OpenXmlWorkbook::cell($sign * (float) $invoice->grand_total, 'total')],
            [$this->label('paid', $locale), OpenXmlWorkbook::cell($invoice->document_type === 'credit_note' ? 0 : (float) $invoice->total_paid, 'currency')],
            [$this->label('remaining', $locale), OpenXmlWorkbook::cell((float) $invoice->remaining_balance, 'currency')],
            [$this->label('original', $locale), $invoice->originalInvoice?->invoice_number],
            [$this->label('compliance', $locale), $invoice->compliance_status],
            [$this->label('integrity', $locale), $invoice->integrity_hash],
            [$this->label('generated_at', $locale), now(config('app.timezone'))->toIso8601String()],
            [$this->label('disclaimer', $locale), $this->label('disclaimer_text', $locale)],
        ];
        $summary[1] = array_map(fn ($value) => OpenXmlWorkbook::cell($value, 'header'), $summary[1]);
        $workbook->addSheet($this->label('summary_sheet', $locale), $summary);

        $items = [[
            $this->label('line', $locale), $this->label('description', $locale), 'SKU',
            $this->label('unit', $locale), $this->label('quantity', $locale),
            $this->label('unit_price', $locale), $this->label('discount', $locale),
            $this->label('taxable', $locale), $this->label('vat_rate', $locale),
            $this->label('vat', $locale), $this->label('line_total', $locale),
            $this->label('tax_treatment', $locale), $this->label('legal_reference', $locale),
        ]];
        $items[0] = array_map(fn ($value) => OpenXmlWorkbook::cell($value, 'header'), $items[0]);
        foreach ($invoice->items as $index => $item) {
            $items[] = [
                OpenXmlWorkbook::cell($index + 1, 'integer'), $item->description, $item->sku_snapshot,
                $item->unit, ['value' => $item->quantity, 'style' => 'integer', 'type' => 'number'],
                ['value' => $item->unit_price, 'style' => 'currency', 'type' => 'number'],
                ['value' => $sign * (float) $item->discount_amount, 'style' => 'currency', 'type' => 'number'],
                ['value' => $sign * (float) $item->taxable_amount, 'style' => 'currency', 'type' => 'number'],
                ['value' => ((float) $item->vat_rate) / 100, 'style' => 'percent', 'type' => 'number'],
                ['value' => $sign * (float) $item->vat_amount, 'style' => 'currency', 'type' => 'number'],
                ['value' => $sign * (float) $item->line_total, 'style' => 'currency', 'type' => 'number'],
                $item->tax_treatment, $item->tax_legal_reference,
            ];
        }
        $items[] = [
            '', $this->label('database_total', $locale), '', '', '', '', '', '', '', '',
            OpenXmlWorkbook::cell($sign * (float) $invoice->grand_total, 'total'), '', '',
        ];
        $workbook->addSheet($this->label('items_sheet', $locale), $items);

        $vat = [[
            $this->label('tax_treatment', $locale), $this->label('vat_rate', $locale),
            $this->label('taxable', $locale), $this->label('vat', $locale),
        ]];
        $vat[0] = array_map(fn ($value) => OpenXmlWorkbook::cell($value, 'header'), $vat[0]);
        foreach ($invoice->items->groupBy(fn ($item) => $item->tax_treatment.'|'.$item->vat_rate) as $group) {
            $first = $group->first();
            $vat[] = [
                $first->tax_treatment,
                ['value' => ((float) $first->vat_rate) / 100, 'style' => 'percent', 'type' => 'number'],
                OpenXmlWorkbook::cell($sign * (float) $group->sum('taxable_amount'), 'currency'),
                OpenXmlWorkbook::cell($sign * (float) $group->sum('vat_amount'), 'currency'),
            ];
        }
        $workbook->addSheet($this->label('vat_sheet', $locale), $vat);

        $payments = [[
            $this->label('payment_date', $locale), $this->label('amount', $locale),
            $this->label('method', $locale), $this->label('reference', $locale),
            $this->label('status', $locale), $this->label('recorded_by', $locale),
            $this->label('reversal_reason', $locale),
        ]];
        $payments[0] = array_map(fn ($value) => OpenXmlWorkbook::cell($value, 'header'), $payments[0]);
        foreach ($invoice->payments->sortBy([['payment_date', 'asc'], ['id', 'asc']]) as $payment) {
            $payments[] = [
                $payment->payment_date?->toDateString(),
                OpenXmlWorkbook::cell((float) $payment->amount, 'currency'),
                $payment->payment_method, $payment->reference_number ?? $payment->transaction_ref,
                $payment->status, $payment->user?->name, $payment->reversal_reason,
            ];
        }
        $workbook->addSheet($this->label('payments_sheet', $locale), $payments);

        return $workbook->bytes();
    }

    private function label(string $key, string $locale): string
    {
        $en = [
            'export_copy' => 'AIMS Invoice Export Copy', 'field' => 'Field', 'value' => 'Value',
            'document_type' => 'Document type', 'document_number' => 'Document number', 'status' => 'Status',
            'payment_status' => 'Payment status', 'invoice_date' => 'Invoice date', 'supply_date' => 'Supply date',
            'due_date' => 'Due date', 'currency' => 'Currency', 'seller' => 'Seller', 'seller_business' => 'Seller business no.',
            'seller_fiscal' => 'Seller fiscal no.', 'seller_vat' => 'Seller VAT no.', 'buyer' => 'Buyer',
            'buyer_business' => 'Buyer business no.', 'buyer_fiscal' => 'Buyer fiscal no.', 'buyer_vat' => 'Buyer VAT no.',
            'subtotal' => 'Subtotal', 'discount' => 'Discount', 'taxable' => 'Taxable amount', 'vat' => 'VAT amount',
            'grand_total' => 'Grand total', 'paid' => 'Paid', 'remaining' => 'Remaining', 'original' => 'Original invoice',
            'compliance' => 'Compliance status', 'integrity' => 'Integrity checksum', 'generated_at' => 'Generated at',
            'disclaimer' => 'Notice', 'disclaimer_text' => 'Export copy for accounting preparation. This workbook is not proof of TAK/EDI filing or a fiscal receipt.',
            'summary_sheet' => 'Invoice Summary', 'items_sheet' => 'Line Items', 'vat_sheet' => 'VAT Summary',
            'payments_sheet' => 'Payments', 'line' => 'Line', 'description' => 'Description', 'unit' => 'Unit',
            'quantity' => 'Quantity', 'unit_price' => 'Unit price', 'line_total' => 'Line total', 'vat_rate' => 'VAT rate',
            'tax_treatment' => 'Tax treatment', 'legal_reference' => 'Legal reference', 'database_total' => 'Recorded document total',
            'payment_date' => 'Payment date', 'amount' => 'Amount', 'method' => 'Method', 'reference' => 'Reference',
            'recorded_by' => 'Recorded by', 'reversal_reason' => 'Reversal reason',
        ];
        $sq = [
            'export_copy' => 'Kopje eksporti e faturës AIMS', 'field' => 'Fusha', 'value' => 'Vlera',
            'document_type' => 'Lloji i dokumentit', 'document_number' => 'Numri i dokumentit', 'status' => 'Statusi',
            'payment_status' => 'Statusi i pagesës', 'invoice_date' => 'Data e faturës', 'supply_date' => 'Data e furnizimit',
            'due_date' => 'Afati i pagesës', 'currency' => 'Valuta', 'seller' => 'Shitësi', 'seller_business' => 'NUI i shitësit',
            'seller_fiscal' => 'Nr. fiskal i shitësit', 'seller_vat' => 'Nr. TVSH i shitësit', 'buyer' => 'Blerësi',
            'buyer_business' => 'NUI i blerësit', 'buyer_fiscal' => 'Nr. fiskal i blerësit', 'buyer_vat' => 'Nr. TVSH i blerësit',
            'subtotal' => 'Nëntotali', 'discount' => 'Zbritja', 'taxable' => 'Baza e tatueshme', 'vat' => 'Shuma e TVSH-së',
            'grand_total' => 'Totali', 'paid' => 'Paguar', 'remaining' => 'Mbetja', 'original' => 'Fatura origjinale',
            'compliance' => 'Statusi i pajtueshmërisë', 'integrity' => 'Kontrolli i integritetit', 'generated_at' => 'Gjeneruar më',
            'disclaimer' => 'Njoftim', 'disclaimer_text' => 'Kopje eksporti për përgatitje kontabël. Ky skedar nuk dëshmon dorëzim në ATK/EDI dhe nuk është kupon fiskal.',
            'summary_sheet' => 'Përmbledhja', 'items_sheet' => 'Artikujt', 'vat_sheet' => 'Përmbledhja TVSH',
            'payments_sheet' => 'Pagesat', 'line' => 'Rreshti', 'description' => 'Përshkrimi', 'unit' => 'Njësia',
            'quantity' => 'Sasia', 'unit_price' => 'Çmimi për njësi', 'line_total' => 'Totali i rreshtit', 'vat_rate' => 'Norma TVSH',
            'tax_treatment' => 'Trajtimi tatimor', 'legal_reference' => 'Baza ligjore', 'database_total' => 'Totali i regjistruar',
            'payment_date' => 'Data e pagesës', 'amount' => 'Shuma', 'method' => 'Mënyra', 'reference' => 'Referenca',
            'recorded_by' => 'Regjistruar nga', 'reversal_reason' => 'Arsyeja e kthimit',
        ];
        if ($locale === 'en') {
            return $en[$key] ?? $key;
        }
        if ($locale === 'sq') {
            return $sq[$key] ?? $key;
        }

        return ($sq[$key] ?? $key).' / '.($en[$key] ?? $key);
    }
}
