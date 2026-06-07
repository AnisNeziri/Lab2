<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->paymentService->listForCompany());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'exists:invoices,id'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'in:card,bank_transfer,cash'],
        ]);

        $invoice = Invoice::findOrFail($validated['invoice_id']);
        $transaction = $this->paymentService->processPayment($invoice, $validated);

        return response()->json($transaction, 201);
    }
}
