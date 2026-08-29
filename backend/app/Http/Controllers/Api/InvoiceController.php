<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceCreditNoteRequest;
use App\Http\Requests\InvoiceRequest;
use App\Http\Requests\InvoiceVoidRequest;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\InvoiceWorkbookService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceWorkbookService $workbooks,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,issued,partially_paid,paid,credited,void,unpaid,sent,overdue'],
            'payment_status' => ['nullable', 'in:unpaid,partially_paid,paid,overdue'],
            'document_type' => ['nullable', 'in:invoice,credit_note'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->invoices->list($validated));
    }

    public function store(InvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $invoice = $request->boolean('issue_now')
            ? $this->invoices->createAndIssue($data)
            : $this->invoices->createDraft($data);

        return response()->json($invoice, 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json($this->invoices->find($invoice));
    }

    public function update(InvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        return response()->json($this->invoices->updateDraft($invoice, $request->validated()));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->invoices->deleteDraft($invoice);

        return response()->json(null, 204);
    }

    public function issue(Invoice $invoice): JsonResponse
    {
        return response()->json($this->invoices->issue($invoice));
    }

    public function void(InvoiceVoidRequest $request, Invoice $invoice): JsonResponse
    {
        return response()->json($this->invoices->voidDraft($invoice, $request->validated('reason')));
    }

    public function creditNote(InvoiceCreditNoteRequest $request, Invoice $invoice): JsonResponse
    {
        return response()->json($this->invoices->fullCreditNote($invoice, $request->validated('reason')), 201);
    }

    public function downloadPdf(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate(['locale' => ['nullable', 'in:en,sq,bilingual']]);
        $locale = $validated['locale'] ?? 'bilingual';
        $invoice = $this->invoices->find($invoice);
        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'vatSummary' => $this->invoices->vatSummary($invoice),
            'labels' => $this->pdfLabels($locale),
        ]);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions([
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
        ]);
        $number = $invoice->invoice_number ?: 'draft-'.$invoice->id;

        return response()->json([
            'pdf' => base64_encode($pdf->output()),
            'filename' => strtolower($invoice->document_type).'-'.$number.'.pdf',
        ]);
    }

    public function downloadExcel(Request $request, Invoice $invoice): Response
    {
        $validated = $request->validate(['locale' => ['nullable', 'in:en,sq,bilingual']]);
        $invoice = $this->invoices->find($invoice);
        abort_if($invoice->status === 'draft', 422, 'Issue the invoice before exporting its Excel copy.');
        $bytes = $this->workbooks->build($invoice, $validated['locale'] ?? 'bilingual');
        $number = preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoice->invoice_number ?: 'invoice-'.$invoice->id);
        $filename = strtolower($invoice->document_type).'-'.$number.'.xlsx';

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function pdfLabels(string $locale): array
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
