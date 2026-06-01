<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovementController extends Controller
{
    public function index(): JsonResponse
    {
        $movements = StockMovement::with('product.category')
            ->latest()
            ->limit(50)
            ->get();

        return response()->json($movements);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'type' => ['required', 'in:in,out'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = DB::transaction(function () use ($validated) {
            $product = Product::lockForUpdate()->findOrFail($validated['product_id']);
            $quantityBefore = $product->quantity;

            if ($validated['type'] === 'out' && $quantityBefore < $validated['quantity']) {
                throw ValidationException::withMessages([
                    'quantity' => ['Not enough stock available for this adjustment.'],
                ]);
            }

            $quantityAfter = $validated['type'] === 'in'
                ? $quantityBefore + $validated['quantity']
                : $quantityBefore - $validated['quantity'];

            $product->update(['quantity' => $quantityAfter]);

            return StockMovement::create([
                'product_id' => $product->id,
                'type' => $validated['type'],
                'quantity' => $validated['quantity'],
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reason' => $validated['reason'] ?? null,
            ]);
        });

        $movement->load('product.category');

        return response()->json($movement, 201);
    }
}
