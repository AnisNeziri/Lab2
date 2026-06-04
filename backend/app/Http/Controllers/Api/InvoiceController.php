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

    public function downloadPdf($id)
    {
        $invoice = Invoice::with('items.product')->findOrFail($id);

        // Load HTML template and bind data
        $pdf = Pdf::loadView('invoices.pdf', compact('invoice'));

        // Return PDF stream or attachment download
        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }
}
