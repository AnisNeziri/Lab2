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
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'type' => ['nullable', 'in:in,out'],
        ]);

        $query = StockMovement::with('product.category')->latest();

        if (! empty($validated['product_id'])) {
            $query->where('product_id', $validated['product_id']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $movements = $query->limit(50)->get();

        return response()->json($movements);
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'type' => ['nullable', 'in:in,out'],
        ]);

        $query = StockMovement::with('product')->latest();

        if (! empty($validated['product_id'])) {
            $query->where('product_id', $validated['product_id']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $movements = $query->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="stock-movements.csv"',
        ];

        $callback = function () use ($movements) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'Product', 'SKU', 'Type', 'Quantity', 'Before', 'After', 'Reason']);

            foreach ($movements as $movement) {
                fputcsv($handle, [
                    $movement->created_at->toDateTimeString(),
                    $movement->product?->name ?? '',
                    $movement->product?->sku ?? '',
                    $movement->type,
                    $movement->quantity,
                    $movement->quantity_before,
                    $movement->quantity_after,
                    $movement->reason ?? '',
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
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
