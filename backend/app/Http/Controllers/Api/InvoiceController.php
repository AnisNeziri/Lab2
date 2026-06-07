<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceService $invoiceService
    ) {}

    public function index(): JsonResponse
    {
        $invoices = Invoice::latest('issued_at')->get();

        return response()->json($invoices);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'issued_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'status' => ['nullable', 'in:draft,unpaid,sent,paid,overdue'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $invoice = $this->invoiceService->store($validated);

        return response()->json($invoice, 201);
    }

    public function show($id): JsonResponse
    {
        $invoice = Invoice::with('items.product')->findOrFail($id);

        return response()->json($invoice);
    }

    public function downloadPdf($id): JsonResponse
    {
        try {
            set_time_limit(120);

            $invoice = Invoice::with('items.product')->findOrFail($id);

            if (! view()->exists('invoices.pdf')) {
                return response()->json([
                    'error' => 'Invoice PDF template is not available.',
                ], 500);
            }

            $pdf = Pdf::loadView('invoices.pdf', compact('invoice'));
            $pdf->setPaper('A4', 'portrait');
            $pdf->setOptions([
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'sans-serif',
            ]);

            $base64 = base64_encode($pdf->output());

            return response()->json([
                'pdf' => $base64,
                'filename' => "invoice-{$invoice->invoice_number}.pdf",
            ]);

        } catch (\Throwable $e) {
            \Log::error('PDF generation failed: '.$e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'error' => 'Could not generate invoice PDF.',
            ], 500);
        }
    }
}
