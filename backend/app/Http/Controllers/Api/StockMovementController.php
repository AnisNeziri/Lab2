<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function __construct(
        private StockMovementService $stockService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'type' => ['nullable', 'in:in,out'],
        ]);

        return response()->json($this->stockService->list($validated));
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'type' => ['nullable', 'in:in,out'],
        ]);

        return $this->stockService->exportCsv($validated);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'type' => ['required', 'in:in,out'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = $this->stockService->store($validated);

        return response()->json($movement, 201);
    }
}
