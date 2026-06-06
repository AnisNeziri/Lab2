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
            $invoice = Invoice::with('items.product')->findOrFail($id);

            if (!view()->exists('invoices.pdf')) {
                return response()->json([
                    'error' => 'View mungon',
                    'path' => 'resources/views/invoices/pdf.blade.php'
                ], 500);
            }

            // Gjenero PDF
            $pdf = Pdf::loadView('invoices.pdf', compact('invoice'));
            $pdf->setPaper('A4', 'portrait');

            $base64 = base64_encode($pdf->output());

            return response()->json([
                'pdf' => $base64,
                'filename' => "invoice-{$invoice->invoice_number}.pdf"
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Gabim gjatë gjenerimit të PDF',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}
