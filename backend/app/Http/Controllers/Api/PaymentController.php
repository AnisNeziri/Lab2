<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentReversalRequest;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('company_id', $request->user()->company_id)],
            'amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01'],
            'payment_method' => ['nullable', 'in:card,bank_transfer,cash,cheque,other'],
            'payment_date' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'financial_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $request->user()->company_id)],
        ]);

        $invoice = Invoice::findOrFail($validated['invoice_id']);
        $transaction = $this->paymentService->processPayment($invoice, $validated);

        return response()->json($transaction, 201);
    }

    public function reverse(PaymentReversalRequest $request, PaymentTransaction $payment): JsonResponse
    {
        $transaction = $this->paymentService->reversePayment(
            $payment,
            $request->validated('reason')
        );

        return response()->json($transaction);
    }
}
