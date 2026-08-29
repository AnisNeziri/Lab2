<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\WarehouseOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseOperationsController extends Controller
{
    public function __construct(private readonly WarehouseOperationsService $operations) {}

    public function storeWarehouse(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate($this->warehouseRules($companyId));

        return response()->json($this->operations->createWarehouse($validated), 201);
    }

    public function updateWarehouse(Request $request, Warehouse $warehouse): JsonResponse
    {
        $validated = $request->validate($this->warehouseRules($request->user()->company_id, $warehouse->id, true));

        return response()->json($this->operations->updateWarehouse($warehouse, $validated));
    }

    public function destroyWarehouse(Warehouse $warehouse): JsonResponse
    {
        $this->operations->deleteWarehouse($warehouse);

        return response()->json(null, 204);
    }

    public function locations(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate(['warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)]]);

        return response()->json($this->operations->locations(isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null));
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $validated = $request->validate($this->locationRules($request->user()->company_id));

        return response()->json($this->operations->createLocation($validated), 201);
    }

    public function updateLocation(Request $request, WarehouseLocation $location): JsonResponse
    {
        $validated = $request->validate($this->locationRules($request->user()->company_id, true));

        return response()->json($this->operations->updateLocation($location, $validated));
    }

    public function destroyLocation(WarehouseLocation $location): JsonResponse
    {
        $this->operations->deleteLocation($location);

        return response()->json(null, 204);
    }

    public function transfers(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'in_transit', 'partially_received', 'received', 'cancelled'])],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->operations->transfers($validated));
    }

    public function showTransfer(StockTransfer $stockTransfer): JsonResponse
    {
        return response()->json($this->operations->findTransfer($stockTransfer));
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        $validated = $request->validate($this->transferRules($request->user()->company_id));

        return response()->json($this->operations->createTransfer($validated), 201);
    }

    public function updateTransfer(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $validated = $request->validate($this->transferRules($request->user()->company_id, true));

        return response()->json($this->operations->updateTransfer($stockTransfer, $validated));
    }

    public function dispatchTransfer(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $validated = $request->validate(['idempotency_key' => ['required', 'uuid']]);

        return response()->json($this->operations->dispatch($stockTransfer, $validated['idempotency_key']));
    }

    public function receiveTransfer(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.accepted_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.damaged_quantity' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($this->operations->receive($stockTransfer, $validated));
    }

    public function cancelTransfer(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json($this->operations->cancel($stockTransfer, $validated['reason']));
    }

    private function warehouseRules(int $companyId, ?int $ignoreId = null, bool $update = false): array
    {
        return [
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('warehouses', 'name')->where('company_id', $companyId)->ignore($ignoreId)],
            'code' => [$update ? 'sometimes' : 'required', 'string', 'max:50', Rule::unique('warehouses', 'code')->where('company_id', $companyId)->ignore($ignoreId)],
            'address' => ['nullable', 'string', 'max:500'],
            'length_m' => ['nullable', 'numeric', 'min:1', 'max:10000'],
            'width_m' => ['nullable', 'numeric', 'min:1', 'max:10000'],
            'height_m' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'floor_count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    private function locationRules(int $companyId, bool $update = false): array
    {
        return [
            'warehouse_id' => [$update ? 'sometimes' : 'required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'parent_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', $companyId)],
            'type' => [$update ? 'sometimes' : 'required', Rule::in(['zone', 'rack', 'shelf', 'bin'])],
            'code' => [$update ? 'sometimes' : 'required', 'string', 'max:30'],
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:120'],
            'floor_level' => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function transferRules(int $companyId, bool $update = false): array
    {
        return [
            'source_warehouse_id' => [$update ? 'sometimes' : 'required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'destination_warehouse_id' => [$update ? 'sometimes' : 'required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'source_location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', $companyId)],
            'destination_location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', $companyId)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => [$update ? 'sometimes' : 'required', 'array', 'min:1', 'max:250'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId), 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'items.*.trace_allocations' => ['nullable', 'array', 'max:1000'],
            'items.*.trace_allocations.*.inventory_lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')->where('company_id', $companyId)],
            'items.*.trace_allocations.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.trace_allocations.*.serial_number' => ['nullable', 'string', 'max:191'],
            'items.*.trace_allocations.*.expiry_at' => ['nullable', 'date'],
            'items.*.trace_allocations.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
        ];
    }
}
