<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductSupplierRequest;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Services\SupplierCatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSupplierController extends Controller
{
    public function __construct(private readonly SupplierCatalogueService $catalogue) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if ($request->has('active')) {
            $filters['active'] = $request->boolean('active');
        }

        return response()->json($this->catalogue->list($filters));
    }

    public function store(ProductSupplierRequest $request): JsonResponse
    {
        return response()->json($this->catalogue->create($request->validated()), 201);
    }

    public function show(ProductSupplier $productSupplier): JsonResponse
    {
        return response()->json($productSupplier->load(['product', 'supplier', 'priceHistory.changedBy:id,name']));
    }

    public function update(ProductSupplierRequest $request, ProductSupplier $productSupplier): JsonResponse
    {
        return response()->json($this->catalogue->update($productSupplier, $request->validated()));
    }

    public function destroy(ProductSupplier $productSupplier): JsonResponse
    {
        return response()->json($this->catalogue->deactivate($productSupplier));
    }

    public function priceHistory(ProductSupplier $productSupplier): JsonResponse
    {
        return response()->json($this->catalogue->priceHistory($productSupplier));
    }

    public function supplierPerformance(Supplier $supplier): JsonResponse
    {
        return response()->json($this->catalogue->performance($supplier));
    }
}
