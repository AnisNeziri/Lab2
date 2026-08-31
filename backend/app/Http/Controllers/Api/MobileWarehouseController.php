<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MobileWarehouseService;
use App\Services\WarehouseInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MobileWarehouseController extends Controller
{
    public function __construct(private readonly MobileWarehouseService $mobile) {}

    public function bootstrap(): JsonResponse
    {
        return response()->json($this->mobile->bootstrap());
    }

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:255']]);

        return response()->json($this->mobile->lookup($validated['code']));
    }

    public function receive(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate([
            'purchase_order_id' => ['required', 'integer', Rule::exists('purchase_orders', 'id')->where('company_id', $companyId)],
            'purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', $companyId)],
            'accepted_quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'damaged_quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'rejected_quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'accepted_base_quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'damaged_base_quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'supplier_document_number' => ['nullable', 'string', 'max:255'],
            'received_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'allow_expired_receipt' => ['nullable', 'boolean'],
            'expired_receipt_reason' => ['nullable', 'required_if:allow_expired_receipt,true', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
            'trace_allocations' => ['nullable', 'array', 'max:1000'],
            'trace_allocations.*.stock_state' => ['nullable', Rule::in(['available', 'damaged'])],
            'trace_allocations.*.inventory_lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')->where('company_id', $companyId)],
            'trace_allocations.*.lot_number' => ['nullable', 'string', 'max:100'],
            'trace_allocations.*.serial_number' => ['nullable', 'string', 'max:191'],
            'trace_allocations.*.supplier_batch' => ['nullable', 'string', 'max:100'],
            'trace_allocations.*.manufactured_at' => ['nullable', 'date'],
            'trace_allocations.*.expiry_at' => ['nullable', 'date'],
            'trace_allocations.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
        ]);

        return response()->json($this->mobile->receive($validated));
    }

    public function move(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate($this->movementRules($companyId, true));

        return response()->json($this->mobile->move($validated), 201);
    }

    public function count(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate([
            'inventory_count_id' => ['required', 'integer', Rule::exists('inventory_count_sessions', 'id')->where('company_id', $companyId)],
            'count_item_id' => ['nullable', 'integer', Rule::exists('inventory_count_items', 'id')->where('company_id', $companyId)],
            'product_id' => ['required_without:count_item_id', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', $companyId)],
            'stock_state' => ['nullable', Rule::in(WarehouseInventoryService::STATES)],
            'inventory_lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')->where('company_id', $companyId)],
            'counted_quantity' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:191'],
            'supplier_batch' => ['nullable', 'string', 'max:100'],
            'manufactured_at' => ['nullable', 'date'],
            'expiry_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json($this->mobile->count($validated));
    }

    public function pick(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate([
            'stock_transfer_item_id' => ['required', 'integer', 'exists:stock_transfer_items,id'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'location_id' => ['required', 'integer', Rule::exists('warehouse_locations', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
            'trace_allocations' => ['nullable', 'array', 'max:1000'],
            'trace_allocations.*.inventory_lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')->where('company_id', $companyId)],
            'trace_allocations.*.lot_number' => ['nullable', 'string', 'max:100'],
            'trace_allocations.*.serial_number' => ['nullable', 'string', 'max:191'],
            'trace_allocations.*.expiry_at' => ['nullable', 'date'],
            'trace_allocations.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
        ]);

        return response()->json($this->mobile->pick($validated), 201);
    }

    private function movementRules(int $companyId, bool $move): array
    {
        $rules = [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
            'trace_allocations' => ['nullable', 'array', 'max:1000'],
            'trace_allocations.*.inventory_lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')->where('company_id', $companyId)],
            'trace_allocations.*.lot_number' => ['nullable', 'string', 'max:100'],
            'trace_allocations.*.serial_number' => ['nullable', 'string', 'max:191'],
            'trace_allocations.*.expiry_at' => ['nullable', 'date'],
            'trace_allocations.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
        ];
        if ($move) {
            return array_merge($rules, [
                'source_location_id' => ['required', 'integer', Rule::exists('warehouse_locations', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
                'destination_location_id' => ['required', 'integer', 'different:source_location_id', Rule::exists('warehouse_locations', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
                'stock_state' => ['nullable', Rule::in(WarehouseInventoryService::STATES)],
            ]);
        }

        return array_merge($rules, [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', $companyId)],
        ]);
    }
}
