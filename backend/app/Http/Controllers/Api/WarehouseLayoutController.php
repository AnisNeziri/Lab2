<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WarehouseSection;
use App\Services\WarehouseLayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class WarehouseLayoutController extends Controller
{
    public function __construct(
        private WarehouseLayoutService $layout
    ) {}

    public function show(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
        ]);

        return response()->json($this->layout->getLayout($companyId, $validated['warehouse_id'] ?? null));
    }

    public function updateWarehouse(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;

        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'length_m' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'width_m' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'height_m' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'floor_count' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $warehouseId = isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null;
        unset($validated['warehouse_id']);
        $warehouse = $this->layout->updateWarehouse($companyId, $validated, $warehouseId);

        return response()->json([
            'message' => 'Warehouse updated.',
            'warehouse' => $warehouse,
        ]);
    }

    public function storeSection(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $warehouse = $this->layout->warehouseForCompany($companyId, $warehouseId);

        $floorLevel = (int) $request->input('floor_level', 1);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
            'light_color' => ['nullable', 'string', 'max:20'],
            'pos_x' => ['nullable', 'numeric'],
            'pos_z' => ['nullable', 'numeric'],
            'width' => ['nullable', 'numeric', 'min:1', 'max:50'],
            'depth' => ['nullable', 'numeric', 'min:1', 'max:50'],
            'floor_level' => ['nullable', 'integer', 'min:1', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $validated['floor_level'] = $floorLevel;
        unset($validated['warehouse_id']);
        $section = $this->layout->createSection($companyId, $validated, $warehouse->id);

        return response()->json($section, 201);
    }

    public function updateSection(Request $request, WarehouseSection $section): JsonResponse
    {
        $floorLevel = (int) $request->input('floor_level', $section->floor_level ?? 1);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:20'],
            'name' => ['sometimes', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
            'light_color' => ['nullable', 'string', 'max:20'],
            'pos_x' => ['nullable', 'numeric'],
            'pos_z' => ['nullable', 'numeric'],
            'width' => ['nullable', 'numeric', 'min:1', 'max:50'],
            'depth' => ['nullable', 'numeric', 'min:1', 'max:50'],
            'floor_level' => ['nullable', 'integer', 'min:1', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (! isset($validated['floor_level'])) {
            $validated['floor_level'] = $floorLevel;
        }

        return response()->json($this->layout->updateSection($section, $validated));
    }

    public function destroySection(WarehouseSection $section): JsonResponse
    {
        $this->layout->deleteSection($section);

        return response()->json(null, 204);
    }

    public function sectionOptions(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
        ]);
        $layout = $this->layout->getLayout($companyId, $validated['warehouse_id'] ?? null);

        return response()->json($layout['sections']);
    }

    public function distribution(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
        ]);

        return response()->json(
            $this->layout->sectionDistribution($companyId, $validated['warehouse_id'] ?? null)
        );
    }
}
