<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceRepositoryInterface $invoices,
        private InvoiceService $invoiceService
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->invoices->allLatest());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name'            => ['required', 'string', 'max:255'],
            'issued_at'                => ['nullable', 'date'],
            'due_at'                   => ['nullable', 'date'],
            'status'                   => ['nullable', 'in:draft,unpaid,sent,partially_paid,paid,overdue'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'         => ['required', 'integer', 'min:1'],
            'items.*.unit_price'       => ['nullable', 'numeric', 'min:0'],
        ]);

        $invoice = $this->invoiceService->store($validated);

        return response()->json($invoice, 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->invoices->findWithItems($id));
    }

    public function downloadPdf(int $id): JsonResponse
    {
        try {
            set_time_limit(120);

            $invoice = $this->invoices->findWithItems($id);

            if (! view()->exists('invoices.pdf')) {
                return response()->json(['error' => 'Invoice PDF template is not available.'], 500);
            }

            $pdf = Pdf::loadView('invoices.pdf', compact('invoice'));
            $pdf->setPaper('A4', 'portrait');
            $pdf->setOptions([
                'isRemoteEnabled'    => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont'        => 'sans-serif',
            ]);

            return response()->json([
                'pdf'      => base64_encode($pdf->output()),
                'filename' => "invoice-{$invoice->invoice_number}.pdf",
            ]);

        } catch (\Throwable $e) {
            \Log::error('PDF generation failed: '.$e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json(['error' => 'Could not generate invoice PDF.'], 500);
        }
    }
}
