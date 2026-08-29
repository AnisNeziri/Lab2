<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('warehouse_locations', 'floor_level')) {
            Schema::table('warehouse_locations', function (Blueprint $table) {
                $table->unsignedInteger('floor_level')->default(1)->after('path');
            });
        }

        if (! Schema::hasColumn('warehouse_sections', 'warehouse_location_id')) {
            Schema::table('warehouse_sections', function (Blueprint $table) {
                $table->foreignId('warehouse_location_id')
                    ->nullable()
                    ->after('warehouse_id')
                    ->constrained('warehouse_locations')
                    ->nullOnDelete();
                $table->unique('warehouse_location_id', 'warehouse_section_location_unique');
            });
        }

        $now = now();

        DB::table('warehouse_sections')->orderBy('id')->get()->each(function ($section) use ($now) {
            if ($section->warehouse_location_id) {
                return;
            }

            $floor = max(1, (int) ($section->floor_level ?? 1));
            $path = $floor > 1 ? 'L'.$floor.'-'.$section->code : $section->code;
            $location = DB::table('warehouse_locations')
                ->where('warehouse_id', $section->warehouse_id)
                ->where('path', $path)
                ->first();

            if (! $location) {
                $locationId = DB::table('warehouse_locations')->insertGetId([
                    'company_id' => $section->company_id,
                    'warehouse_id' => $section->warehouse_id,
                    'parent_id' => null,
                    'type' => 'zone',
                    'code' => $section->code,
                    'name' => $section->name,
                    'path' => $path,
                    'floor_level' => $floor,
                    'is_active' => (bool) $section->is_active,
                    'sort_order' => (int) $section->sort_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $locationId = $location->id;
                DB::table('warehouse_locations')->where('id', $locationId)->update([
                    'floor_level' => $floor,
                    'updated_at' => $now,
                ]);
            }

            DB::table('warehouse_sections')->where('id', $section->id)->update([
                'warehouse_location_id' => $locationId,
                'updated_at' => $now,
            ]);
        });

        DB::table('warehouse_locations')->orderBy('id')->get()->each(function ($location) use ($now) {
            if (DB::table('warehouse_sections')->where('warehouse_location_id', $location->id)->exists()) {
                return;
            }

            $floor = max(1, (int) ($location->floor_level ?? 1));
            $warehouse = DB::table('warehouses')->where('id', $location->warehouse_id)->first();
            $length = max(8.0, (float) ($warehouse?->length_m ?? 80));
            $width = max(8.0, (float) ($warehouse?->width_m ?? 90));
            $existing = DB::table('warehouse_sections')
                ->where('warehouse_id', $location->warehouse_id)
                ->where('floor_level', $floor)
                ->count();
            $columns = max(1, (int) floor(($length - 4) / 6) + 1);
            $row = intdiv($existing, $columns);
            $column = $existing % $columns;
            $posX = min($length / 2 - 2, -$length / 2 + 2 + ($column * 6));
            $posZ = min($width / 2 - 2, -$width / 2 + 2 + ($row * 6));

            $preferredCode = strlen($location->path) <= 20 ? strtoupper($location->path) : 'LOC-'.$location->id;
            $code = DB::table('warehouse_sections')
                ->where('warehouse_id', $location->warehouse_id)
                ->where('floor_level', $floor)
                ->where('code', $preferredCode)
                ->exists()
                    ? 'LOC-'.$location->id
                    : $preferredCode;

            DB::table('warehouse_sections')->insert([
                'company_id' => $location->company_id,
                'warehouse_id' => $location->warehouse_id,
                'warehouse_location_id' => $location->id,
                'code' => $code,
                'name' => $location->name,
                'color' => '#6366f1',
                'light_color' => '#818cf8',
                'pos_x' => $posX,
                'pos_z' => $posZ,
                'width' => 4,
                'depth' => 4,
                'floor_level' => $floor,
                'sort_order' => $existing + 1,
                'is_active' => (bool) $location->is_active,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('warehouse_sections', 'warehouse_location_id')) {
            Schema::table('warehouse_sections', function (Blueprint $table) {
                $table->dropUnique('warehouse_section_location_unique');
                $table->dropConstrainedForeignId('warehouse_location_id');
            });
        }

        if (Schema::hasColumn('warehouse_locations', 'floor_level')) {
            Schema::table('warehouse_locations', function (Blueprint $table) {
                $table->dropColumn('floor_level');
            });
        }
    }
};
