<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierInvoiceRequest;
use App\Models\Expense;
use App\Services\SupplierInvoiceMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierInvoiceController extends Controller
{
    public function __construct(private readonly SupplierInvoiceMatchingService $matching) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'supplier_id' => ['nullable', 'integer'],
            'match_status' => ['nullable', 'in:unmatched,partially_matched,matched,exception,approved'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return response()->json($this->matching->list($filters));
    }

    public function show(Expense $supplierInvoice): JsonResponse { return response()->json($this->matching->show($supplierInvoice)); }
    public function store(SupplierInvoiceRequest $request): JsonResponse { return response()->json($this->matching->create($request->validated()), 201); }
    public function update(SupplierInvoiceRequest $request, Expense $supplierInvoice): JsonResponse { return response()->json($this->matching->update($supplierInvoice, $request->validated())); }
    public function recalculate(Expense $supplierInvoice): JsonResponse { return response()->json($this->matching->recalculate($supplierInvoice)); }

    public function approve(Request $request, Expense $supplierInvoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'min:3', 'max:2000']]);
        return response()->json($this->matching->approve($supplierInvoice, $data['reason'] ?? null));
    }

    public function allocatePayment(Request $request, Expense $supplierInvoice): JsonResponse
    {
        $data = $request->validate([
            'purchase_order_payment_id' => [
                'required', 'integer',
                Rule::exists('purchase_order_payments', 'id')->where('company_id', $request->user()->company_id),
            ],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        return response()->json($this->matching->allocatePayment(
            $supplierInvoice,
            (int) $data['purchase_order_payment_id'],
            $data['amount'],
            $data['reason'] ?? null,
            $data['idempotency_key'],
        ));
    }
}
