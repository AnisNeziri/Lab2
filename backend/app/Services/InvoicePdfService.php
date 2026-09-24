<?php
namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoicePdfService
{
    public function render(Invoice $invoice, string $locale = 'bilingual'): string
    {
        return Pdf::loadView('invoices.pdf', ['invoice'=>$invoice, 'vatSummary'=>app(InvoiceService::class)->vatSummary($invoice), 'labels'=>$this->labels($locale)])
            ->setPaper('A4', 'portrait')->setOptions(['isRemoteEnabled'=>false, 'isHtml5ParserEnabled'=>true, 'defaultFont'=>'DejaVu Sans'])->output();
    }

    public function labels(string $locale): array
    {
        $catalog = [
            'en' => [
                'invoice' => 'TAX INVOICE', 'credit_note' => 'CREDIT NOTE', 'draft' => 'DRAFT',
                'seller' => 'Seller', 'buyer' => 'Buyer', 'number' => 'Document No.', 'issue_date' => 'Issue date',
                'supply_date' => 'Supply date', 'due_date' => 'Due date', 'description' => 'Goods / services',
                'sku' => 'SKU', 'unit' => 'Unit', 'quantity' => 'Qty', 'unit_price' => 'Unit price excl. VAT',
                'discount' => 'Discount', 'taxable' => 'Taxable base', 'vat_rate' => 'VAT', 'vat' => 'VAT amount',
                'total' => 'Total', 'subtotal' => 'Subtotal', 'taxable_total' => 'Taxable total',
                'vat_total' => 'VAT total', 'grand_total' => 'Grand total', 'paid' => 'Paid', 'balance' => 'Balance',
                'business_number' => 'NUI / Business no.', 'fiscal_number' => 'Fiscal no.', 'vat_number' => 'VAT no.',
                'payment' => 'Payment information', 'notes' => 'Notes', 'original' => 'Original invoice',
                'reason' => 'Correction reason', 'vat_summary' => 'VAT summary', 'legal_basis' => 'Legal basis',
            ],
            'sq' => [
                'invoice' => 'FATURË TATIMORE', 'credit_note' => 'NOTË KREDITORE', 'draft' => 'DRAFT',
                'seller' => 'Shitësi', 'buyer' => 'Blerësi', 'number' => 'Nr. i dokumentit', 'issue_date' => 'Data e lëshimit',
                'supply_date' => 'Data e furnizimit', 'due_date' => 'Afati i pagesës', 'description' => 'Mallrat / shërbimet',
                'sku' => 'SKU', 'unit' => 'Njësia', 'quantity' => 'Sasia', 'unit_price' => 'Çmimi pa TVSH',
                'discount' => 'Zbritja', 'taxable' => 'Baza e tatueshme', 'vat_rate' => 'TVSH', 'vat' => 'Shuma e TVSH-së',
                'total' => 'Totali', 'subtotal' => 'Nëntotali', 'taxable_total' => 'Totali i tatueshëm',
                'vat_total' => 'TVSH totale', 'grand_total' => 'Totali përfundimtar', 'paid' => 'Paguar', 'balance' => 'Mbetja',
                'business_number' => 'NUI / Nr. i biznesit', 'fiscal_number' => 'Nr. fiskal', 'vat_number' => 'Nr. i TVSH-së',
                'payment' => 'Informacioni i pagesës', 'notes' => 'Shënime', 'original' => 'Fatura origjinale',
                'reason' => 'Arsyeja e korrigjimit', 'vat_summary' => 'Përmbledhja e TVSH-së', 'legal_basis' => 'Baza ligjore',
            ],
        ];
        if ($locale !== 'bilingual') {
            return $catalog[$locale] ?? $catalog['en'];
        }

        $labels = [];
        foreach ($catalog['sq'] as $key => $label) {
            $labels[$key] = $label.' / '.$catalog['en'][$key];
        }

        return $labels;
    }
}
