<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    public function index(): JsonResponse
    {
        $invoices = Invoice::latest()->get();
        return response()->json($invoices);
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

            if (!view()->exists('invoices.pdf')) {
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
                'filename' => "invoice-{$invoice->invoice_number}.pdf"
            ]);

        } catch (\Throwable $e) {
            \Log::error('PDF generation failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'error' => 'Could not generate invoice PDF.',
            ], 500);
        }
    }
}
