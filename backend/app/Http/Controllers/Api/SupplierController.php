<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct(
        private SupplierRepositoryInterface $suppliers
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->suppliers->allWithProductCount());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:50'],
            'email'   => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $supplier = $this->suppliers->create($validated);

        return response()->json($supplier, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $supplier = $this->suppliers->findById($id);

        if (! $supplier) {
            return response()->json(['message' => 'Supplier not found.'], 404);
        }

        $validated = $request->validate([
            'name'    => ['sometimes', 'required', 'string', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:50'],
            'email'   => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->suppliers->update($supplier, $validated));
    }

    public function destroy(int $id): JsonResponse
    {
        $supplier = $this->suppliers->findById($id);

        if (! $supplier) {
            return response()->json(['message' => 'Supplier not found.'], 404);
        }

        if ($this->suppliers->hasProducts($supplier)) {
            return response()->json([
                'message' => 'Cannot delete a supplier that still has products assigned.',
            ], 422);
        }

        $this->suppliers->delete($supplier);

        return response()->json(null, 204);
    }
}
