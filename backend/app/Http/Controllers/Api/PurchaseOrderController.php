<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrderPaymentRequest;
use App\Http\Requests\PaymentReversalRequest;
use App\Models\PurchaseOrderPayment;
use App\Http\Requests\PurchaseOrderReceiveRequest;
use App\Http\Requests\PurchaseOrderRequest;
use App\Http\Requests\PurchaseOrderStatusRequest;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,confirmed,ordered,partially_received,received,completed,cancelled'],
            'payment_status' => ['nullable', 'in:unpaid,partially_paid,paid,overdue'],
            'ordered_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'overdue' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:recent,total_desc,due_asc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->orders->list($filters));
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json($this->orders->find($purchaseOrder));
    }

    public function store(PurchaseOrderRequest $request): JsonResponse
    {
        return response()->json($this->orders->create($request->validated()), 201);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json($this->orders->update($purchaseOrder, $request->validated()));
    }

    public function pay(PurchaseOrderPaymentRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json($this->orders->pay($purchaseOrder, $request->validated()), 201);
    }

    public function reversePayment(PaymentReversalRequest $request, PurchaseOrderPayment $payment): JsonResponse
    {
        return response()->json($this->orders->reversePayment($payment, $request->validated('reason')));
    }

    public function receive(PurchaseOrderReceiveRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json($this->orders->receive($purchaseOrder, $request->validated()));
    }

    public function status(PurchaseOrderStatusRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json($this->orders->changeStatus($purchaseOrder, $request->validated('status'), $request->validated('reason')));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return response()->json($this->orders->cancel($purchaseOrder, $data['reason'] ?? null));
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->orders->deleteCompleted($purchaseOrder);

        return response()->json(['message' => 'Completed purchase order deleted.']);
    }

    public function statement(PurchaseOrder $purchaseOrder): StreamedResponse
    {
        return $this->orders->statement($purchaseOrder);
    }
}
