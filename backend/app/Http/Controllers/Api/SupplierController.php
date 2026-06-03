<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        $suppliers = Supplier::withCount('products')
            ->orderBy('name')
            ->get();

        return response()->json($suppliers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $supplier = Supplier::create($validated);

        return response()->json($supplier, 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $supplier->update($validated);

        return response()->json($supplier);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        if ($supplier->products()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a supplier that still has products assigned.',
            ], 422);
        }

        $supplier->delete();

        return response()->json(null, 204);
    }
}
