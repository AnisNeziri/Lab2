<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'type' => ['required', 'in:in,out'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'stock_state' => ['nullable', Rule::in(WarehouseInventoryService::STATES)],
            'trace_allocations' => ['nullable', 'array', 'max:1000'],
            'trace_allocations.*.inventory_lot_id' => ['nullable', 'integer', 'exists:inventory_lots,id'],
            'trace_allocations.*.lot_number' => ['nullable', 'string', 'max:100'],
            'trace_allocations.*.serial_number' => ['nullable', 'string', 'max:191'],
            'trace_allocations.*.supplier_batch' => ['nullable', 'string', 'max:100'],
            'trace_allocations.*.manufactured_at' => ['nullable', 'date'],
            'trace_allocations.*.expiry_at' => ['nullable', 'date'],
            'trace_allocations.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
        ]);

        $validated['movement_code'] = $validated['type'] === 'in'
            ? 'manual_adjustment_in'
            : 'manual_adjustment_out';
        $validated['source_type'] = 'manual_adjustment';

        $movement = $this->stockService->store($validated);

        return response()->json($movement, 201);
    }
}
