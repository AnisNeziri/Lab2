<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseSection;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseLayoutService
{
    public function getOrCreatePrimaryWarehouse(int $companyId): Warehouse
    {
        $warehouse = Warehouse::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
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
            'is_default' => true,
        ]);
    }

    public function warehouseForCompany(int $companyId, ?int $warehouseId = null): Warehouse
    {
        if (! $warehouseId) {
            return $this->getOrCreatePrimaryWarehouse($companyId);
        }

        return Warehouse::where('company_id', $companyId)->findOrFail($warehouseId);
    }

    public function getLayout(int $companyId, ?int $warehouseId = null): array
    {
        $warehouse = $this->warehouseForCompany($companyId, $warehouseId);

        if ($warehouse->is_default && $warehouse->sections()->count() === 0 && $warehouse->locations()->count() === 0) {
            $this->seedDefaultSections($warehouse);
        }

        $this->synchronizeWarehouse($warehouse);

        $sections = $warehouse->sections()
            ->with('location:id,warehouse_id,parent_id,type,code,name,path,floor_level,is_active')
            ->where('is_active', true)
            ->orderBy('floor_level')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        $locationCounts = WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereNotNull('location_id')
            ->where('quantity', '>', 0)
            ->select('location_id', DB::raw('count(distinct product_id) as product_count'))
            ->groupBy('location_id')
            ->pluck('product_count', 'location_id');

        $legacyCounts = Product::where('company_id', $companyId)
            ->whereNotNull('location_code')
            ->select('location_code', DB::raw('count(*) as count'))
            ->groupBy('location_code')
            ->pluck('count', 'location_code');

        return [
            'warehouse' => $warehouse,
            'warehouses' => Warehouse::where('company_id', $companyId)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'floor_count', 'is_default']),
            'sections' => $sections->map(function (WarehouseSection $section) use ($locationCounts, $legacyCounts) {
                $location = $section->location;
                $legacyCount = (int) ($legacyCounts[$section->code] ?? 0);
                $locationCount = $location ? (int) ($locationCounts[$location->id] ?? 0) : 0;

                return [
                    ...$section->toArray(),
                    'display_code' => $location?->path ?: $section->code,
                    'location_code' => $location?->code,
                    'location_path' => $location?->path,
                    'location_type' => $location?->type,
                    'product_count' => max($locationCount, $legacyCount),
                ];
            })->values(),
        ];
    }

    public function updateWarehouse(int $companyId, array $data, ?int $warehouseId = null): Warehouse
    {
        $warehouse = $this->warehouseForCompany($companyId, $warehouseId);

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

    public function createSection(int $companyId, array $data, ?int $warehouseId = null): WarehouseSection
    {
        $warehouse = $this->warehouseForCompany($companyId, $warehouseId);

        return DB::transaction(function () use ($companyId, $data, $warehouse) {
            $floor = max(1, (int) ($data['floor_level'] ?? 1));
            if ($floor > (int) $warehouse->floor_count) {
                $warehouse->update(['floor_count' => $floor]);
            }
            $width = (float) ($data['width'] ?? 4);
            $depth = (float) ($data['depth'] ?? 4);
            $position = isset($data['pos_x'], $data['pos_z'])
                ? ['pos_x' => (float) $data['pos_x'], 'pos_z' => (float) $data['pos_z']]
                : $this->automaticPosition($warehouse, $floor, $width, $depth);
            $code = strtoupper(trim($data['code']));
            $path = $this->topLevelPath($code, $floor);
            if (WarehouseLocation::where('warehouse_id', $warehouse->id)->where('path', $path)->exists()
                || WarehouseSection::where('warehouse_id', $warehouse->id)->where('floor_level', $floor)->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => ['This location code is already used on the selected level.']]);
            }

            $section = WarehouseSection::create([
                'company_id' => $companyId,
                'warehouse_id' => $warehouse->id,
                'code' => $code,
                'name' => trim($data['name']),
                'color' => $data['color'] ?? '#6366f1',
                'light_color' => $data['light_color'] ?? '#818cf8',
                ...$position,
                'width' => $width,
                'depth' => $depth,
                'floor_level' => $floor,
                'sort_order' => $data['sort_order'] ?? ($warehouse->sections()->max('sort_order') + 1),
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $this->synchronizeSection($section)->fresh('location');
        });
    }

    public function updateSection(WarehouseSection $section, array $data): WarehouseSection
    {
        return DB::transaction(function () use ($section, $data) {
            $section = WarehouseSection::query()->lockForUpdate()->findOrFail($section->id);
            $oldCode = $section->code;
            $oldFloor = (int) $section->floor_level;
            $oldLegacyKey = $oldFloor > 1 ? 'L'.$oldFloor.'-'.$oldCode : $oldCode;
            $logicalCode = isset($data['code']) ? strtoupper(trim($data['code'])) : ($section->location?->code ?? $section->code);
            $section->update([
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

            $this->synchronizeSection($section, true, $logicalCode);
            $section->refresh();
            $newLegacyKey = (int) $section->floor_level > 1 ? 'L'.$section->floor_level.'-'.$section->code : $section->code;
            if ($oldLegacyKey !== $newLegacyKey) {
                Product::where('company_id', $section->company_id)
                    ->where('location_code', $oldLegacyKey)
                    ->update(['location_code' => $newLegacyKey]);
            }

            return $section->fresh('location');
        });
    }

    public function deleteSection(WarehouseSection $section): void
    {
        DB::transaction(function () use ($section) {
            $section = WarehouseSection::query()->with('location')->lockForUpdate()->findOrFail($section->id);
            $location = $section->location;
            $legacyCodes = array_filter([$section->code, $location?->path]);
            if ($location && ($location->children()->exists() || $this->locationHasStock($location))) {
                throw ValidationException::withMessages([
                    'section' => ['Move stock and remove child locations before deleting this section.'],
                ]);
            }
            if (Product::where('company_id', $section->company_id)->whereIn('location_code', $legacyCodes)->exists()) {
                throw ValidationException::withMessages(['section' => ['Move products out of this location before deleting it.']]);
            }

            Product::where('company_id', $section->company_id)
                ->whereIn('location_code', array_filter([$section->code, $location?->path]))
                ->update(['location_code' => null]);
            if ($location) {
                WarehouseStock::query()->where('location_id', $location->id)->delete();
                DB::table('inventory_trace_balances')->where('location_id', $location->id)->where('quantity', '<=', 0)->delete();
            }
            $section->delete();
            $location?->delete();
        });
    }

    public function synchronizeWarehouse(Warehouse $warehouse): void
    {
        DB::transaction(function () use ($warehouse) {
            $warehouse->sections()->whereNull('warehouse_location_id')->lockForUpdate()->orderBy('id')->get()
                ->each(fn (WarehouseSection $section) => $this->synchronizeSection($section));
            $warehouse->locations()->whereDoesntHave('section')->lockForUpdate()->orderBy('id')->get()
                ->each(fn (WarehouseLocation $location) => $this->synchronizeLocation($location));
        });
    }

    public function synchronizeLocation(WarehouseLocation $location): WarehouseSection
    {
        $location->loadMissing('warehouse', 'section');
        $section = $location->section;

        if ($section) {
            $section->update([
                'code' => $location->parent_id ? 'LOC-'.$location->id : $location->code,
                'name' => $location->name,
                'floor_level' => $location->floor_level,
                'is_active' => $location->is_active,
                'sort_order' => $location->sort_order,
            ]);

            return $section->fresh('location');
        }

        $position = $this->automaticPosition($location->warehouse, $location->floor_level, 4, 4);
        $preferred = $location->parent_id ? 'LOC-'.$location->id : strtoupper($location->code);
        $code = WarehouseSection::where('warehouse_id', $location->warehouse_id)
            ->where('floor_level', $location->floor_level)
            ->where('code', $preferred)
            ->exists() ? 'LOC-'.$location->id : $preferred;

        return WarehouseSection::create([
            'company_id' => $location->company_id,
            'warehouse_id' => $location->warehouse_id,
            'warehouse_location_id' => $location->id,
            'code' => $code,
            'name' => $location->name,
            'color' => '#6366f1',
            'light_color' => '#818cf8',
            ...$position,
            'width' => 4,
            'depth' => 4,
            'floor_level' => $location->floor_level,
            'sort_order' => $location->sort_order ?: ($location->warehouse->sections()->max('sort_order') + 1),
            'is_active' => $location->is_active,
        ])->fresh('location');
    }

    public function deleteSectionForLocation(WarehouseLocation $location): void
    {
        $section = WarehouseSection::where('warehouse_location_id', $location->id)->first();
        if (! $section) {
            return;
        }

        Product::where('company_id', $section->company_id)
            ->whereIn('location_code', array_filter([$section->code, $location->path]))
            ->update(['location_code' => null]);
        $section->delete();
    }

    public function sectionDistribution(int $companyId, ?int $warehouseId = null): array
    {
        $layout = $this->getLayout($companyId, $warehouseId);

        return collect($layout['sections'])->map(fn ($section) => [
            'name' => $section['display_code'],
            'value' => $section['product_count'],
        ])->sortByDesc('value')->values()->all();
    }

    private function synchronizeSection(WarehouseSection $section, bool $sectionIsSource = false, ?string $logicalCode = null): WarehouseSection
    {
        $section->loadMissing('location');
        $location = $section->location;
        $floor = max(1, (int) $section->floor_level);

        if (! $location) {
            $path = $this->topLevelPath($section->code, $floor);
            $location = WarehouseLocation::where('warehouse_id', $section->warehouse_id)
                ->where('path', $path)
                ->first();
            if (! $location) {
                $location = WarehouseLocation::create([
                    'company_id' => $section->company_id,
                    'warehouse_id' => $section->warehouse_id,
                    'parent_id' => null,
                    'type' => 'zone',
                    'code' => $section->code,
                    'name' => $section->name,
                    'path' => $path,
                    'floor_level' => $floor,
                    'is_active' => $section->is_active,
                    'sort_order' => $section->sort_order,
                ]);
            }
            $section->update(['warehouse_location_id' => $location->id]);
        } elseif ($sectionIsSource) {
            $oldPath = $location->path;
            $code = $logicalCode ?: $location->code;
            $newPath = $location->parent
                ? $location->parent->path.'/'.$code
                : $this->topLevelPath($code, $floor);
            if (WarehouseLocation::where('warehouse_id', $location->warehouse_id)->where('path', $newPath)->whereKeyNot($location->id)->exists()) {
                throw ValidationException::withMessages(['code' => ['This location code is already used on the selected level.']]);
            }
            $location->update([
                'code' => $code,
                'name' => $section->name,
                'path' => $newPath,
                'floor_level' => $floor,
                'is_active' => $section->is_active,
                'sort_order' => $section->sort_order,
            ]);
            $section->update(['code' => $location->parent_id ? 'LOC-'.$location->id : $code]);
            if ($oldPath !== $newPath) {
                WarehouseLocation::where('path', 'like', $oldPath.'/%')->get()->each(function (WarehouseLocation $child) use ($oldPath, $newPath, $floor) {
                    $child->update([
                        'path' => $newPath.substr($child->path, strlen($oldPath)),
                        'floor_level' => $floor,
                    ]);
                    $this->synchronizeLocation($child);
                });
            }
        }

        return $section;
    }

    private function automaticPosition(Warehouse $warehouse, int $floor, float $width, float $depth): array
    {
        $length = max(8.0, (float) ($warehouse->length_m ?: 80));
        $buildingWidth = max(8.0, (float) ($warehouse->width_m ?: 90));
        $halfWidth = $width / 2;
        $halfDepth = $depth / 2;
        $spacing = 1.0;
        $sections = $warehouse->sections()->where('floor_level', $floor)->get();

        for ($z = -$buildingWidth / 2 + $halfDepth; $z <= $buildingWidth / 2 - $halfDepth; $z += $depth + $spacing) {
            for ($x = -$length / 2 + $halfWidth; $x <= $length / 2 - $halfWidth; $x += $width + $spacing) {
                $overlaps = $sections->contains(function (WarehouseSection $section) use ($x, $z, $width, $depth, $spacing) {
                    return abs((float) $section->pos_x - $x) < (((float) $section->width + $width) / 2 + $spacing)
                        && abs((float) $section->pos_z - $z) < (((float) $section->depth + $depth) / 2 + $spacing);
                });
                if (! $overlaps) {
                    return ['pos_x' => round($x, 2), 'pos_z' => round($z, 2)];
                }
            }
        }

        return ['pos_x' => 0, 'pos_z' => 0];
    }

    private function topLevelPath(string $code, int $floor): string
    {
        $code = strtoupper(trim($code));

        return $floor > 1 ? 'L'.$floor.'-'.$code : $code;
    }

    private function seedDefaultSections(Warehouse $warehouse): void
    {
        $defaults = [
            ['code' => 'A1', 'name' => 'Zone A — Shelf A1', 'color' => '#6366f1', 'light_color' => '#818cf8', 'pos_x' => -18, 'pos_z' => -18],
            ['code' => 'A2', 'name' => 'Zone A — Shelf A2', 'color' => '#6366f1', 'light_color' => '#818cf8', 'pos_x' => -11, 'pos_z' => -18],
            ['code' => 'A3', 'name' => 'Zone A — Shelf A3', 'color' => '#6366f1', 'light_color' => '#818cf8', 'pos_x' => -18, 'pos_z' => -10],
            ['code' => 'A4', 'name' => 'Zone A — Shelf A4', 'color' => '#6366f1', 'light_color' => '#818cf8', 'pos_x' => -11, 'pos_z' => -10],
            ['code' => 'B1', 'name' => 'Zone B — Shelf B1', 'color' => '#f59e0b', 'light_color' => '#fbbf24', 'pos_x' => -2, 'pos_z' => -18],
            ['code' => 'B2', 'name' => 'Zone B — Shelf B2', 'color' => '#f59e0b', 'light_color' => '#fbbf24', 'pos_x' => 5, 'pos_z' => -18],
            ['code' => 'B3', 'name' => 'Zone B — Shelf B3', 'color' => '#f59e0b', 'light_color' => '#fbbf24', 'pos_x' => -2, 'pos_z' => -10],
            ['code' => 'B4', 'name' => 'Zone B — Shelf B4', 'color' => '#f59e0b', 'light_color' => '#fbbf24', 'pos_x' => 5, 'pos_z' => -10],
            ['code' => 'C1', 'name' => 'Zone C — Shelf C1', 'color' => '#10b981', 'light_color' => '#34d399', 'pos_x' => 14, 'pos_z' => -18],
            ['code' => 'C2', 'name' => 'Zone C — Shelf C2', 'color' => '#10b981', 'light_color' => '#34d399', 'pos_x' => 21, 'pos_z' => -18],
            ['code' => 'D1', 'name' => 'Zone D — Shelf D1', 'color' => '#8b5cf6', 'light_color' => '#a78bfa', 'pos_x' => -8, 'pos_z' => 2],
            ['code' => 'D2', 'name' => 'Zone D — Shelf D2', 'color' => '#8b5cf6', 'light_color' => '#a78bfa', 'pos_x' => -1, 'pos_z' => 2],
        ];

        foreach ($defaults as $index => $section) {
            WarehouseSection::create([
                'company_id' => $warehouse->company_id,
                'warehouse_id' => $warehouse->id,
                ...$section,
                'floor_level' => 1,
                'width' => 4,
                'depth' => 4,
                'sort_order' => $index + 1,
                'is_active' => true,
            ]);
        }

        $warehouse->update([
            'length_m' => $warehouse->length_m ?? 80,
            'width_m' => $warehouse->width_m ?? 90,
            'height_m' => $warehouse->height_m ?? 8,
            'floor_count' => $warehouse->floor_count ?? 1,
        ]);
    }

    private function locationHasStock(WarehouseLocation $location): bool
    {
        return WarehouseStock::query()->where('location_id', $location->id)
            ->where(function ($query) {
                $query->where('quantity', '>', 0)
                    ->orWhere('reserved_quantity', '>', 0)
                    ->orWhere('damaged_quantity', '>', 0)
                    ->orWhere('quarantine_quantity', '>', 0)
                    ->orWhere('blocked_quantity', '>', 0);
            })->exists();
    }
}
