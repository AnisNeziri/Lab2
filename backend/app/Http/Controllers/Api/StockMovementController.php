<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustmentRequest;
use App\Services\StockMovementService;
use App\Services\WarehouseInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockMovementController extends Controller
{
    public function __construct(
        private StockMovementService $stockService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'type' => ['nullable', 'in:in,out'],
            'movement_code' => ['nullable', Rule::in(StockMovementService::MOVEMENT_CODES)],
            'source_type' => ['nullable', 'string', 'max:80'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'stock_state' => ['nullable', Rule::in(WarehouseInventoryService::STATES)],
        ]);

        return response()->json($this->stockService->list($validated));
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'type' => ['nullable', 'in:in,out'],
            'movement_code' => ['nullable', Rule::in(StockMovementService::MOVEMENT_CODES)],
            'source_type' => ['nullable', 'string', 'max:80'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'stock_state' => ['nullable', Rule::in(WarehouseInventoryService::STATES)],
        ]);

        return $this->stockService->exportCsv($validated);
    }

    public function store(StockAdjustmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['movement_code'] = $validated['type'] === 'in'
            ? 'manual_adjustment_in'
            : 'manual_adjustment_out';
        $validated['source_type'] = 'manual_adjustment';

        $movement = $this->stockService->store($validated);

        return response()->json($movement, 201);
    }
}
