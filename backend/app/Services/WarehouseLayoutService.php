<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseSection;
use Illuminate\Support\Facades\DB;

class WarehouseLayoutService
{
    public function getOrCreatePrimaryWarehouse(int $companyId): Warehouse
    {
        $warehouse = Warehouse::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($warehouse) {
            return $warehouse;
        }

        return Warehouse::create([
            'company_id' => $companyId,
            'name' => 'Main Warehouse',
            'code' => 'WH-MAIN',
            'length_m' => 80,
            'width_m' => 90,
            'height_m' => 8,
            'floor_count' => 1,
            'is_active' => true,
        ]);
    }

    public function getLayout(int $companyId): array
    {
        $warehouse = $this->getOrCreatePrimaryWarehouse($companyId);

        if ($warehouse->sections()->count() === 0) {
            $this->seedDefaultSections($warehouse);
        }

        $sections = $warehouse->sections()
            ->where('is_active', true)
            ->orderBy('floor_level')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        $productCounts = Product::where('company_id', $companyId)
            ->whereNotNull('location_code')
            ->select('location_code', DB::raw('count(*) as count'))
            ->groupBy('location_code')
            ->pluck('count', 'location_code');

        return [
            'warehouse' => $warehouse,
            'sections' => $sections->map(fn (WarehouseSection $s) => [
                ...$s->toArray(),
                'product_count' => (int) ($productCounts[$s->code] ?? 0),
            ]),
        ];
    }

    public function updateWarehouse(int $companyId, array $data): Warehouse
    {
        $warehouse = $this->getOrCreatePrimaryWarehouse($companyId);

        $warehouse->update([
            'name' => $data['name'] ?? $warehouse->name,
            'code' => $data['code'] ?? $warehouse->code,
            'address' => $data['address'] ?? $warehouse->address,
            'length_m' => $data['length_m'] ?? $warehouse->length_m,
            'width_m' => $data['width_m'] ?? $warehouse->width_m,
            'height_m' => $data['height_m'] ?? $warehouse->height_m ?? 8,
            'floor_count' => $data['floor_count'] ?? $warehouse->floor_count ?? 1,
        ]);

        return $warehouse->fresh();
    }

    public function createSection(int $companyId, array $data): WarehouseSection
    {
        $warehouse = $this->getOrCreatePrimaryWarehouse($companyId);

        return WarehouseSection::create([
            'company_id' => $companyId,
            'warehouse_id' => $warehouse->id,
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'color' => $data['color'] ?? '#6366f1',
            'light_color' => $data['light_color'] ?? '#818cf8',
            'pos_x' => $data['pos_x'] ?? 0,
            'pos_z' => $data['pos_z'] ?? 0,
            'width' => $data['width'] ?? 4,
            'depth' => $data['depth'] ?? 4,
            'floor_level' => $data['floor_level'] ?? 1,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function updateSection(WarehouseSection $section, array $data): WarehouseSection
    {
        $section->update([
            'code' => isset($data['code']) ? strtoupper($data['code']) : $section->code,
            'name' => $data['name'] ?? $section->name,
            'color' => $data['color'] ?? $section->color,
            'light_color' => $data['light_color'] ?? $section->light_color,
            'pos_x' => $data['pos_x'] ?? $section->pos_x,
            'pos_z' => $data['pos_z'] ?? $section->pos_z,
            'width' => $data['width'] ?? $section->width,
            'depth' => $data['depth'] ?? $section->depth,
            'floor_level' => $data['floor_level'] ?? $section->floor_level,
            'sort_order' => $data['sort_order'] ?? $section->sort_order,
            'is_active' => $data['is_active'] ?? $section->is_active,
        ]);

        return $section->fresh();
    }

    public function deleteSection(WarehouseSection $section): void
    {
        Product::where('company_id', $section->company_id)
            ->where('location_code', $section->code)
            ->update(['location_code' => null]);

        $section->delete();
    }

    public function sectionDistribution(int $companyId): array
    {
        return Product::where('company_id', $companyId)
            ->whereNotNull('location_code')
            ->select('location_code', DB::raw('sum(quantity) as total_qty'))
            ->groupBy('location_code')
            ->orderByDesc('total_qty')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->location_code,
                'value' => (int) $row->total_qty,
            ])
            ->values()
            ->all();
    }

    private function seedDefaultSections(Warehouse $warehouse): void
    {
        $defaults = [
            ['code' => 'A1', 'name' => 'Zone A — Shelf A1', 'color' => '#6366f1', 'light_color' => '#818cf8', 'pos_x' => -18, 'pos_z' => -18, 'sort_order' => 1],
            ['code' => 'A2', 'name' => 'Zone A — Shelf A2', 'color' => '#6366f1', 'light_color' => '#818cf8', 'pos_x' => -11, 'pos_z' => -18, 'sort_order' => 2],
            ['code' => 'A3', 'name' => 'Zone A — Shelf A3', 'color' => '#6366f1', 'light_color' => '#818cf8', 'pos_x' => -18, 'pos_z' => -10, 'sort_order' => 3],
            ['code' => 'A4', 'name' => 'Zone A — Shelf A4', 'color' => '#6366f1', 'light_color' => '#818cf8', 'pos_x' => -11, 'pos_z' => -10, 'sort_order' => 4],
            ['code' => 'B1', 'name' => 'Zone B — Shelf B1', 'color' => '#f59e0b', 'light_color' => '#fbbf24', 'pos_x' => -2,  'pos_z' => -18, 'sort_order' => 5],
            ['code' => 'B2', 'name' => 'Zone B — Shelf B2', 'color' => '#f59e0b', 'light_color' => '#fbbf24', 'pos_x' => 5,   'pos_z' => -18, 'sort_order' => 6],
            ['code' => 'B3', 'name' => 'Zone B — Shelf B3', 'color' => '#f59e0b', 'light_color' => '#fbbf24', 'pos_x' => -2,  'pos_z' => -10, 'sort_order' => 7],
            ['code' => 'B4', 'name' => 'Zone B — Shelf B4', 'color' => '#f59e0b', 'light_color' => '#fbbf24', 'pos_x' => 5,   'pos_z' => -10, 'sort_order' => 8],
            ['code' => 'C1', 'name' => 'Zone C — Shelf C1', 'color' => '#10b981', 'light_color' => '#34d399', 'pos_x' => 14,  'pos_z' => -18, 'sort_order' => 9],
            ['code' => 'C2', 'name' => 'Zone C — Shelf C2', 'color' => '#10b981', 'light_color' => '#34d399', 'pos_x' => 21,  'pos_z' => -18, 'sort_order' => 10],
            ['code' => 'C3', 'name' => 'Zone C — Shelf C3', 'color' => '#10b981', 'light_color' => '#34d399', 'pos_x' => 14,  'pos_z' => -10, 'sort_order' => 11],
            ['code' => 'C4', 'name' => 'Zone C — Shelf C4', 'color' => '#10b981', 'light_color' => '#34d399', 'pos_x' => 21,  'pos_z' => -10, 'sort_order' => 12],
            ['code' => 'D1', 'name' => 'Zone D — Shelf D1', 'color' => '#8b5cf6', 'light_color' => '#a78bfa', 'pos_x' => -8,  'pos_z' => 2,   'sort_order' => 13],
            ['code' => 'D2', 'name' => 'Zone D — Shelf D2', 'color' => '#8b5cf6', 'light_color' => '#a78bfa', 'pos_x' => -1,  'pos_z' => 2,   'sort_order' => 14],
            ['code' => 'D3', 'name' => 'Zone D — Shelf D3', 'color' => '#8b5cf6', 'light_color' => '#a78bfa', 'pos_x' => 6,   'pos_z' => 2,   'sort_order' => 15],
            ['code' => 'D4', 'name' => 'Zone D — Shelf D4', 'color' => '#8b5cf6', 'light_color' => '#a78bfa', 'pos_x' => 13,  'pos_z' => 2,   'sort_order' => 16],
        ];

        foreach ($defaults as $section) {
            WarehouseSection::create([
                'company_id' => $warehouse->company_id,
                'warehouse_id' => $warehouse->id,
                ...$section,
            ]);
        }

        $warehouse->update([
            'length_m' => $warehouse->length_m ?? 80,
            'width_m' => $warehouse->width_m ?? 90,
            'height_m' => 8,
            'floor_count' => $warehouse->floor_count ?? 1,
        ]);
    }
}
