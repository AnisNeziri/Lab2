<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryCountApprovalRequest;
use App\Http\Requests\InventoryCountCreateRequest;
use App\Http\Requests\InventoryCountRecordRequest;
use App\Http\Requests\InventoryCountRecountRequest;
use App\Models\InventoryCountSession;
use App\Services\InventoryCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryCountController extends Controller
{
    public function __construct(private readonly InventoryCountService $counts) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(InventoryCountService::STATUSES)],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->counts->list($validated));
    }

    public function store(InventoryCountCreateRequest $request): JsonResponse
    {
        return response()->json($this->counts->create($request->validated()), 201);
    }

    public function show(InventoryCountSession $inventoryCount): JsonResponse
    {
        return response()->json($this->counts->find($inventoryCount));
    }

    public function record(InventoryCountRecordRequest $request, InventoryCountSession $inventoryCount): JsonResponse
    {
        return response()->json($this->counts->record($inventoryCount, $request->validated()));
    }

    public function recount(InventoryCountRecountRequest $request, InventoryCountSession $inventoryCount): JsonResponse
    {
        $validated = $request->validated();

        return response()->json($this->counts->requestRecount($inventoryCount, $validated['item_ids'], $validated['reason']));
    }

    public function submit(InventoryCountSession $inventoryCount): JsonResponse
    {
        return response()->json($this->counts->submit($inventoryCount));
    }

    public function approve(InventoryCountApprovalRequest $request, InventoryCountSession $inventoryCount): JsonResponse
    {
        return response()->json($this->counts->approve($inventoryCount, $request->validated('reason')));
    }

    public function cancel(InventoryCountApprovalRequest $request, InventoryCountSession $inventoryCount): JsonResponse
    {
        return response()->json($this->counts->cancel($inventoryCount, $request->validated('reason')));
    }
}
