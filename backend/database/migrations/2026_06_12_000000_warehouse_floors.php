<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouses', 'floor_count')) {
                $table->unsignedInteger('floor_count')->default(1)->after('height_m');
            }
        });

        Schema::table('warehouse_sections', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouse_sections', 'floor_level')) {
                $table->unsignedInteger('floor_level')->default(1)->after('depth');
            }
        });

        if (! Schema::hasTable('warehouse_sections')) {
            return;
        }

        $hasOldUnique = collect(Schema::getIndexes('warehouse_sections'))
            ->contains(fn ($idx) => $idx['name'] === 'warehouse_sections_warehouse_id_code_unique');

        $hasNewUnique = collect(Schema::getIndexes('warehouse_sections'))
            ->contains(fn ($idx) => $idx['name'] === 'warehouse_sections_wh_floor_code_unique');

        if ($hasOldUnique && ! $hasNewUnique) {
            Schema::table('warehouse_sections', function (Blueprint $table) {
                $table->dropForeign(['warehouse_id']);
            });

            Schema::table('warehouse_sections', function (Blueprint $table) {
                $table->dropUnique('warehouse_sections_warehouse_id_code_unique');
                $table->unique(['warehouse_id', 'floor_level', 'code'], 'warehouse_sections_wh_floor_code_unique');
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('warehouse_sections')) {
            $hasNewUnique = collect(Schema::getIndexes('warehouse_sections'))
                ->contains(fn ($idx) => $idx['name'] === 'warehouse_sections_wh_floor_code_unique');

            if ($hasNewUnique) {
                Schema::table('warehouse_sections', function (Blueprint $table) {
                    $table->dropForeign(['warehouse_id']);
                });

                Schema::table('warehouse_sections', function (Blueprint $table) {
                    $table->dropUnique('warehouse_sections_wh_floor_code_unique');
                    $table->unique(['warehouse_id', 'code']);
                    $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
                });
            }

            if (Schema::hasColumn('warehouse_sections', 'floor_level')) {
                Schema::table('warehouse_sections', function (Blueprint $table) {
                    $table->dropColumn('floor_level');
                });
            }
        }

        if (Schema::hasColumn('warehouses', 'floor_count')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->dropColumn('floor_count');
            });
        }
    }
};
