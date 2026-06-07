<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService
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

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->where('company_id', $companyId)],
            'barcode' => ['nullable', 'string', 'max:50', Rule::unique('products', 'barcode')->where('company_id', $companyId)],
            'description' => ['nullable', 'string'],
            'quantity' => ['required', 'integer', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'min_quantity' => ['required', 'integer', 'min:0'],
            'high_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validated['company_id'] = $companyId;
        $product = $this->productService->create($validated);

        return response()->json($product, 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'supplier']);

        $movements = $product->stockMovements()
            ->latest()
            ->limit(20)
            ->get();

        return response()->json([
            'product' => $product,
            'movements' => $movements,
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'exists:categories,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('products', 'sku')->where('company_id', $companyId)->ignore($product->id)],
            'barcode' => ['nullable', 'string', 'max:50', Rule::unique('products', 'barcode')->where('company_id', $companyId)->ignore($product->id)],
            'description' => ['nullable', 'string'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'min_quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'high_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = $this->productService->update($product, $validated);

        return response()->json($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->productService->delete($product);

        return response()->json(null, 204);
    }
}
