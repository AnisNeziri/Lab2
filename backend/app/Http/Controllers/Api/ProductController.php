<?php

namespace App\Http\Controllers\Api;

use App\Events\DashboardUpdated;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\WarehouseSection;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\ProductService;
use App\Services\TraceabilityService;
use App\Services\UnitConversionService;
use App\Support\SafeBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private ProductRepositoryInterface $productRepository,
        private UnitConversionService $unitConversions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'sort' => ['nullable', 'in:name,sku,quantity,min_quantity,price'],
            'direction' => ['nullable', 'in:asc,desc'],
            'low_stock' => ['nullable', 'boolean'],
            'location_code' => ['nullable', 'string', 'max:10'],
        ]);

        $perPage = min($request->integer('per_page', 10), 50);
        $validated['low_stock'] = $request->boolean('low_stock');

        return response()->json($this->productService->list($validated, $perPage));
    }

    public function export()
    {
        return $this->productService->exportCsv();
    }

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:100'],
        ]);

        $product = $this->productService->lookup($validated['sku']);

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json($product);
    }

    public function byShelf(Request $request, string $locationCode): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        $normalized = strtoupper($locationCode);
        $section = WarehouseSection::where('company_id', $companyId)
            ->whereNotNull('warehouse_location_id')
            ->get()
            ->first(function (WarehouseSection $candidate) use ($normalized) {
                $key = (int) $candidate->floor_level > 1
                    ? 'L'.$candidate->floor_level.'-'.$candidate->code
                    : $candidate->code;

                return strtoupper($key) === $normalized;
            });

        if ($section?->warehouse_location_id) {
            $products = Product::with(['category', 'supplier', 'warehouseStock.warehouse:id,name,code'])
                ->whereHas('warehouseStock', fn ($query) => $query
                    ->where('warehouse_id', $section->warehouse_id)
                    ->where('location_id', $section->warehouse_location_id))
                ->orderBy('name')
                ->get();
            $products->each(function (Product $product) use ($section) {
                $balance = $product->warehouseStock->first(fn ($row) => (int) $row->warehouse_id === (int) $section->warehouse_id
                    && (int) $row->location_id === (int) $section->warehouse_location_id);
                if ($balance) {
                    $product->setAttribute('quantity', (float) $balance->available_quantity);
                }
            });

            return response()->json($products);
        }

        $products = $this->productRepository->byLocationCode(
            $normalized,
            $companyId,
        );

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'default_warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->where('company_id', $companyId)],
            'barcode' => ['nullable', 'string', 'max:50', Rule::unique('products', 'barcode')->where('company_id', $companyId)],
            'description' => ['nullable', 'string'],
            'tracking_mode' => ['nullable', Rule::in(TraceabilityService::MODES)],
            'near_expiry_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'fefo_enabled' => ['nullable', 'boolean'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'min_quantity' => ['required', 'numeric', 'min:0'],
            'safety_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'replenishment_history_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'replenishment_review_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'high_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'location_code' => ['nullable', 'string', 'max:20'],
            'price' => ['required', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'unit_conversions' => ['nullable', 'array', 'max:20'],
            'unit_conversions.*.code' => ['required', 'string', 'max:30'],
            'unit_conversions.*.label' => ['nullable', 'string', 'max:100'],
            'unit_conversions.*.conversion_mode' => ['nullable', Rule::in(UnitConversionService::MODES)],
            'unit_conversions.*.factor_to_base' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'unit_conversions.*.is_active' => ['nullable', 'boolean'],
            'opening_trace_allocations' => ['nullable', 'array', 'max:1000'],
            'opening_trace_allocations.*.lot_number' => ['nullable', 'string', 'max:100'],
            'opening_trace_allocations.*.serial_number' => ['nullable', 'string', 'max:191'],
            'opening_trace_allocations.*.supplier_batch' => ['nullable', 'string', 'max:100'],
            'opening_trace_allocations.*.manufactured_at' => ['nullable', 'date'],
            'opening_trace_allocations.*.expiry_at' => ['nullable', 'date'],
            'opening_trace_allocations.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
        ]);

        $this->ensureMeterQuantities($validated, $validated['unit'] ?? 'pcs');
        $validated['company_id'] = $companyId;
        $validated['sku'] = filled($validated['sku'] ?? null)
            ? trim($validated['sku'])
            : $this->generateSku($companyId);
        $validated['location_code'] = $validated['location_code'] ?? null;
        $product = $this->productService->create($validated);
        SafeBroadcast::dispatch(new DashboardUpdated($companyId, 'product.created', [
            'product_id' => $product->id,
            'name' => $product->name,
        ]));

        return response()->json($product, 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'supplier', 'supplierCatalogue.supplier', 'defaultWarehouse', 'units', 'warehouseStock.warehouse', 'warehouseStock.location']);

        $movements = $product->stockMovements()
            ->with(['warehouse:id,name,code', 'location:id,name,code,path'])
            ->latest()
            ->limit(20)
            ->get();

        $product->append('image_url');

        return response()->json([
            'product' => $product,
            'movements' => $movements,
        ]);
    }

    public function uploadImage(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $file = $validated['image'];
        $product->forceFill([
            'image_data' => base64_encode($file->get()),
            'image_mime' => $file->getMimeType() ?: 'image/jpeg',
        ])->save();

        $product = $product->fresh(['category', 'supplier']);
        $product->append('image_url');

        return response()->json($product);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'default_warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('products', 'sku')->where('company_id', $companyId)->ignore($product->id)],
            'barcode' => ['nullable', 'string', 'max:50', Rule::unique('products', 'barcode')->where('company_id', $companyId)->ignore($product->id)],
            'description' => ['nullable', 'string'],
            'tracking_mode' => ['sometimes', Rule::in(TraceabilityService::MODES)],
            'near_expiry_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'fefo_enabled' => ['nullable', 'boolean'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0'],
            'quantity_change_reason' => ['nullable', 'string', 'max:500'],
            'unit' => ['nullable', 'string', 'max:20'],
            'min_quantity' => ['sometimes', 'required', 'numeric', 'min:0'],
            'safety_stock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'reorder_point' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'replenishment_history_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'replenishment_review_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'high_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'location_code' => ['nullable', 'string', 'max:20'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'volume_m3' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'unit_conversions' => ['sometimes', 'array', 'max:20'],
            'unit_conversions.*.code' => ['required', 'string', 'max:30'],
            'unit_conversions.*.label' => ['nullable', 'string', 'max:100'],
            'unit_conversions.*.conversion_mode' => ['nullable', Rule::in(UnitConversionService::MODES)],
            'unit_conversions.*.factor_to_base' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'unit_conversions.*.is_active' => ['nullable', 'boolean'],
        ]);

        $this->ensureMeterQuantities($validated, $validated['unit'] ?? $product->unit);
        if (array_key_exists('sku', $validated) && blank($validated['sku'])) {
            unset($validated['sku']);
        } elseif (array_key_exists('sku', $validated)) {
            $validated['sku'] = trim($validated['sku']);
        }
        $product = $this->productService->update($product, $validated);

        return response()->json($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->productService->delete($product);

        return response()->json(null, 204);
    }

    private function ensureMeterQuantities(array $data, ?string $unit): void
    {
        foreach (['quantity', 'min_quantity', 'safety_stock', 'reorder_point', 'high_stock_threshold'] as $field) {
            if (isset($data[$field])) {
                $this->unitConversions->assertPrecision((float) $data[$field], $unit, $field);
            }
        }
    }

    private function generateSku(int $companyId): string
    {
        do {
            $sku = 'AUTO-'.$companyId.'-'.strtoupper(Str::random(10));
        } while (Product::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->exists());

        return $sku;
    }
}
