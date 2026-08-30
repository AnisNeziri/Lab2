<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BinTransferRequest;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\BinTransferService;
use App\Services\TraceabilityService;
use App\Services\WarehouseInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(
        private readonly WarehouseInventoryService $inventory,
        private readonly TraceabilityService $traceability,
        private readonly BinTransferService $binTransfers,
    ) {}

    public function locator(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', $companyId)],
            'include_empty' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $query = WarehouseStock::query()
            ->with([
                'product:id,name,sku,barcode,unit,tracking_mode,near_expiry_days,fefo_enabled',
                'warehouse:id,name,code',
                'location:id,warehouse_id,name,code,path,type,floor_level',
            ])
            ->when($validated['product_id'] ?? null, fn ($builder, $id) => $builder->where('product_id', $id))
            ->when($validated['warehouse_id'] ?? null, fn ($builder, $id) => $builder->where('warehouse_id', $id))
            ->when($validated['location_id'] ?? null, fn ($builder, $id) => $builder->where('location_id', $id))
            ->when(! ($validated['include_empty'] ?? false), fn ($builder) => $builder->where('quantity', '>', 0))
            ->when($validated['search'] ?? null, fn ($builder, $search) => $builder->whereHas('product', fn ($product) => $product
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")))
            ->orderBy('warehouse_id')->orderBy('location_key')->orderBy('product_id');

        return response()->json($query->paginate($validated['per_page'] ?? 50));
    }

    public function product(Product $product, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ]);
        $balances = $this->inventory->balances(
            $product,
            isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
            isset($validated['location_id']) ? (int) $validated['location_id'] : null,
        );
        $lots = InventoryLot::query()
            ->with(['balances' => fn ($query) => $query
                ->with(['warehouse:id,name,code', 'location:id,name,code,path'])
                ->where('quantity', '>', 0)
                ->when($validated['warehouse_id'] ?? null, fn ($builder, $id) => $builder->where('warehouse_id', $id))
                ->when($validated['location_id'] ?? null, fn ($builder, $id) => $builder->where('location_id', $id))])
            ->withSum('balances as quantity_remaining_sum', 'quantity')
            ->where('product_id', $product->id)
            ->whereHas('balances', fn ($query) => $query
                ->where('quantity', '>', 0)
                ->when($validated['warehouse_id'] ?? null, fn ($builder, $id) => $builder->where('warehouse_id', $id))
                ->when($validated['location_id'] ?? null, fn ($builder, $id) => $builder->where('location_id', $id)))
            ->orderByRaw('CASE WHEN expiry_at IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_at')->get();

        return response()->json([
            'product' => $product,
            'company_quantity' => (float) $product->quantity,
            'balances' => $balances,
            'totals' => collect(WarehouseInventoryService::STATES)->mapWithKeys(fn ($state) => [
                $state => round((float) $balances->sum($state.'_quantity'), 3),
            ]),
            'lots' => $lots,
        ]);
    }

    public function expiring(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'within_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);
        $products = Product::query()
            ->when($validated['product_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->where(fn ($query) => $query
                ->where('expiration_controlled', true)
                ->orWhere('tracking_mode', 'batch_expiry'))
            ->get();
        $lots = $products->flatMap(fn (Product $product) => $this->traceability->expiring(
            $product,
            isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
            isset($validated['within_days']) ? (int) $validated['within_days'] : null,
        ))->sortBy('expiry_at')->values();

        return response()->json($lots);
    }

    public function fefo(Request $request, Product $product): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', $companyId)],
            'stock_state' => ['nullable', Rule::in(WarehouseInventoryService::STATES)],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
        ]);
        $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);
        $locationId = $this->inventory->resolveLocationId(
            $product,
            $warehouse,
            'out',
            (float) $validated['quantity'],
            $validated['stock_state'] ?? 'available',
            isset($validated['location_id']) ? (int) $validated['location_id'] : null,
        );

        return response()->json([
            'location_id' => $locationId,
            'allocations' => $this->traceability->fefoAllocations(
                $product,
                $warehouse,
                $locationId,
                $validated['stock_state'] ?? 'available',
                (float) $validated['quantity'],
            ),
        ]);
    }

    public function moveBin(BinTransferRequest $request): JsonResponse
    {
        return response()->json($this->binTransfers->move($request->validated()), 201);
    }
}
