<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDraftPurchaseOrdersRequest;
use App\Http\Requests\ReplenishmentRequest;
use App\Services\ReplenishmentService;
use Illuminate\Http\JsonResponse;

class ReplenishmentController extends Controller
{
    public function __construct(private readonly ReplenishmentService $replenishment) {}

    public function index(ReplenishmentRequest $request): JsonResponse
    {
        return response()->json($this->replenishment->suggestions($request->validated()));
    }

    public function createDraftPurchaseOrders(CreateDraftPurchaseOrdersRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Draft purchase orders created from the reviewed suggestions.',
            'purchase_orders' => $this->replenishment->createDraftPurchaseOrders($request->validated()),
        ], 201);
    }
}
