<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BinTransferRequest;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\BinTransferService;
use App\Services\InventorySnapshotService;
use App\Services\TraceabilityService;
use App\Services\WarehouseInventoryService;
use App\Support\BarcodeIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(
        private readonly WarehouseInventoryService $inventory,
        private readonly InventorySnapshotService $snapshots,
        private readonly TraceabilityService $traceability,
        private readonly BinTransferService $binTransfers,
    ) {}

    public function control(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = Product::query()->select('id', 'company_id', 'name', 'sku', 'unit', 'quantity', 'min_quantity')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")))
            ->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 25);
        $snapshots = $this->snapshots->forProducts($page->getCollection(), null, $filters['warehouse_id'] ?? null);
        $today = \App\Support\CompanyClock::today()->toDateString();
        $page->setCollection($page->getCollection()->map(function ($product) use ($snapshots, $today) {
            $snapshot = $snapshots[$product->id]; $position = $this->snapshots->position($snapshot); $attention = [];
            if (abs($position['difference']) >= 0.0005 || $position['available'] < 0 || $position['reserved'] < 0) $attention[] = 'integrity_difference';
            if ($position['available'] <= 0) $attention[] = 'out_of_stock';
            elseif ($position['available'] < (float) $product->min_quantity) $attention[] = 'below_minimum';
            if ($position['backordered'] > 0) $attention[] = 'backorders';
            if ($position['quality_hold'] > 0) $attention[] = 'quality_hold';
            if (collect($snapshot['incoming_schedule'])->contains(fn ($s) => $s['expected_at'] && $s['expected_at'] < $today)
                || collect($snapshot['incoming_evidence'])->contains(fn ($s) => $s['eta'] && $s['eta'] < $today && ($s['remaining_base'] === null || $s['remaining_base'] > 0))) $attention[] = 'delayed_incoming';
            if ($position['in_transit'] === null) $attention[] = 'unattributed_receipts_or_cargo';
            return ['product' => $product, 'position' => $position, 'attention' => $attention,
                'incoming_schedule' => array_slice($snapshot['incoming_schedule'], 0, 20), 'incoming_evidence' => $snapshot['incoming_evidence'],
                'sources_truncated' => count($snapshot['incoming_schedule']) > 20 || $snapshot['incoming_evidence_truncated'],
                'url' => '/warehouse-operations?product='.$product->id,
                'replenishment_url' => '/inventory-intelligence?product='.$product->id,
                'orders_url' => '/order-hub?product='.$product->id.'&view=backorders'];
        }));
        return response()->json([...$page->toArray(), 'generated_at' => now()->toIso8601String(),
            'scope' => 'Current page of products. No mixed-unit company quantity total. Facts only; forecasts remain in Inventory Intelligence.']);
    }

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
                'product' => fn ($query) => $query->select('id', 'company_id', 'name', 'sku', 'barcode', 'unit', 'tracking_mode', 'near_expiry_days', 'fefo_enabled', 'quantity', 'min_quantity', 'high_stock_threshold')->withStockTotals(),
                'warehouse:id,name,code',
                'location:id,warehouse_id,name,code,path,type,floor_level',
            ])
            ->when($validated['product_id'] ?? null, fn ($builder, $id) => $builder->where('product_id', $id))
            ->when($validated['warehouse_id'] ?? null, fn ($builder, $id) => $builder->where('warehouse_id', $id))
            ->when($validated['location_id'] ?? null, fn ($builder, $id) => $builder->where('location_id', $id))
            ->when(! ($validated['include_empty'] ?? false), fn ($builder) => $builder->where('quantity', '>', 0))
            ->when($validated['search'] ?? null, function ($builder, $search): void {
                $barcodeIdentity = BarcodeIdentity::normalize($search);
                $builder->whereHas('product', fn ($product) => $product
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->when($barcodeIdentity, fn ($barcode) => $barcode
                        ->orWhere('barcode_normalized', 'like', "%{$barcodeIdentity}%")
                        ->orWhereHas('alternativeBarcodes', fn ($barcodes) => $barcodes
                            ->where('is_active', true)
                            ->where('barcode_normalized', 'like', "%{$barcodeIdentity}%"))));
            })
            ->orderBy('warehouse_id')->orderBy('location_key')->orderBy('product_id');

        return response()->json($query->paginate($validated['per_page'] ?? 50));
    }

    public function product(Product $product, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $request->user()->company_id)],
            'location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', $request->user()->company_id)],
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

        $snapshot = $this->snapshots->forProduct($product, null, $validated['warehouse_id'] ?? null);
        return response()->json([
            'product' => $product,
            'company_quantity' => (float) $product->quantity,
            'inventory' => $snapshot,
            'position' => $this->snapshots->position($snapshot),
            'position_scope' => isset($validated['warehouse_id']) ? 'warehouse' : 'company',
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
        $plans = $this->traceability->outboundLocationAllocations(
            $product,
            $warehouse,
            isset($validated['location_id']) ? (int) $validated['location_id'] : null,
            $validated['stock_state'] ?? 'available',
            (float) $validated['quantity'],
        );

        return response()->json([
            // `location_id` remains for old clients when one bin is enough.
            'location_id' => count($plans) === 1 ? $plans[0]['location_id'] : null,
            'location_allocations' => $plans,
            'allocations' => collect($plans)->flatMap(fn (array $plan) => $plan['trace_allocations'])->values(),
        ]);
    }

    public function moveBin(BinTransferRequest $request): JsonResponse
    {
        return response()->json($this->binTransfers->move($request->validated()), 201);
    }
}
