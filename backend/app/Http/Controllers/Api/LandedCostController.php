<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LandedCostRequest;
use App\Models\LandedCost;
use App\Services\LandedCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandedCostController extends Controller
{
    public function __construct(private readonly LandedCostService $landedCosts) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,posted'],
            'goods_receipt_id' => ['nullable', 'integer'],
            'shipment_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->landedCosts->list($filters));
    }

    public function store(LandedCostRequest $request): JsonResponse
    {
        return response()->json($this->landedCosts->createDraft($request->validated()), 201);
    }

    public function show(LandedCost $landedCost): JsonResponse
    {
        return response()->json($this->landedCosts->find($landedCost));
    }

    public function post(LandedCost $landedCost): JsonResponse
    {
        return response()->json($this->landedCosts->post($landedCost));
    }

    public function destroy(LandedCost $landedCost): JsonResponse
    {
        $this->landedCosts->deleteDraft($landedCost);

        return response()->json(null, 204);
    }
}
