<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('warehouse_stock', 'location_key')) {
            Schema::table('warehouse_stock', function (Blueprint $table) {
                // Zero is the stable identity for legacy/unassigned stock. A
                // separate key avoids the different NULL-unique behaviour of
                // SQLite and MySQL while location_id remains a real nullable FK.
                $table->unsignedBigInteger('location_key')->default(0)->after('location_id');
            });
        }

        DB::table('warehouse_stock')->update([
            'location_key' => DB::raw('COALESCE(location_id, 0)'),
        ]);

        $warehouseStockIndexes = collect(Schema::getIndexes('warehouse_stock'))->pluck('name');
        // The legacy unique index is also the supporting index for the
        // warehouse FK on MariaDB. Give that FK its own index before replacing
        // the legacy uniqueness rule with the bin-aware one.
        if (! $warehouseStockIndexes->contains('ws_warehouse_fk_idx')) {
            Schema::table('warehouse_stock', fn (Blueprint $table) =>
                $table->index('warehouse_id', 'ws_warehouse_fk_idx'));
        }
        $warehouseStockIndexes = collect(Schema::getIndexes('warehouse_stock'))->pluck('name');
        if ($warehouseStockIndexes->contains('warehouse_stock_warehouse_id_product_id_unique')) {
            Schema::table('warehouse_stock', fn (Blueprint $table) =>
                $table->dropUnique('warehouse_stock_warehouse_id_product_id_unique'));
        }
        $warehouseStockIndexes = collect(Schema::getIndexes('warehouse_stock'))->pluck('name');
        if (! $warehouseStockIndexes->contains('warehouse_stock_bin_unique')) {
            Schema::table('warehouse_stock', fn (Blueprint $table) =>
                $table->unique(['warehouse_id', 'product_id', 'location_key'], 'warehouse_stock_bin_unique'));
        }
        if (! $warehouseStockIndexes->contains('warehouse_stock_locator_idx')) {
            Schema::table('warehouse_stock', fn (Blueprint $table) =>
                $table->index(['company_id', 'product_id', 'warehouse_id', 'location_id'], 'warehouse_stock_locator_idx'));
        }

        if (! Schema::hasColumn('products', 'tracking_mode')) {
            Schema::table('products', fn (Blueprint $table) =>
                $table->string('tracking_mode', 20)->default('none')->after('default_warehouse_id'));
        }
        if (! Schema::hasColumn('products', 'near_expiry_days')) {
            Schema::table('products', fn (Blueprint $table) =>
                $table->unsignedInteger('near_expiry_days')->default(30)->after('tracking_mode'));
        }
        if (! Schema::hasColumn('products', 'fefo_enabled')) {
            Schema::table('products', fn (Blueprint $table) =>
                $table->boolean('fefo_enabled')->default(true)->after('near_expiry_days'));
        }

        if (! Schema::hasColumn('stock_movements', 'location_quantity_before')) {
            Schema::table('stock_movements', fn (Blueprint $table) =>
                $table->decimal('location_quantity_before', 18, 3)->nullable()->after('warehouse_quantity_after'));
        }
        if (! Schema::hasColumn('stock_movements', 'location_quantity_after')) {
            Schema::table('stock_movements', fn (Blueprint $table) =>
                $table->decimal('location_quantity_after', 18, 3)->nullable()->after('location_quantity_before'));
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['location_quantity_before', 'location_quantity_after']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['tracking_mode', 'near_expiry_days', 'fefo_enabled']);
        });

        // Multiple bin rows cannot be represented by the legacy constraint.
        // Collapse them without deleting quantity before restoring it.
        DB::table('warehouse_stock')
            ->select('warehouse_id', 'product_id')
            ->groupBy('warehouse_id', 'product_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(function ($group): void {
                $rows = DB::table('warehouse_stock')
                    ->where('warehouse_id', $group->warehouse_id)
                    ->where('product_id', $group->product_id)
                    ->orderBy('id')
                    ->get();
                $keeper = $rows->first();
                foreach (['quantity', 'available_quantity', 'reserved_quantity', 'damaged_quantity', 'quarantine_quantity', 'blocked_quantity'] as $column) {
                    $keeper->{$column} = $rows->sum(fn ($row) => (float) $row->{$column});
                }
                DB::table('warehouse_stock')->where('id', $keeper->id)->update([
                    'location_id' => null,
                    'quantity' => $keeper->quantity,
                    'available_quantity' => $keeper->available_quantity,
                    'reserved_quantity' => $keeper->reserved_quantity,
                    'damaged_quantity' => $keeper->damaged_quantity,
                    'quarantine_quantity' => $keeper->quarantine_quantity,
                    'blocked_quantity' => $keeper->blocked_quantity,
                ]);
                DB::table('warehouse_stock')->whereIn('id', $rows->skip(1)->pluck('id'))->delete();
            });

        Schema::table('warehouse_stock', function (Blueprint $table) {
            $table->dropIndex('warehouse_stock_locator_idx');
            $table->dropUnique('warehouse_stock_bin_unique');
            $table->unique(['warehouse_id', 'product_id']);
            $table->dropColumn('location_key');
        });
    }
};
