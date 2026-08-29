<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_units')) {
            DB::table('product_units')->update([
                'allow_purchase' => true,
                'allow_sale' => true,
                'is_default_purchase' => false,
                'is_default_sale' => false,
            ]);
        }

        if (Schema::hasTable('shipments') && Schema::hasColumn('shipments', 'archived_at')) {
            DB::table('shipments')
                ->whereNull('archived_at')
                ->where(function ($query) {
                    $query->whereIn('tracking_provider', ['manual', 'demo', 'disabled'])
                        ->orWhereIn('tracking_mode', ['manual', 'demo', 'disabled']);
                })
                ->update([
                    'archived_at' => now(),
                    'current_lat' => null,
                    'current_lng' => null,
                    'distance_to_port_km' => null,
                    'position_updated_at' => null,
                    'speed_knots' => null,
                    'course' => null,
                    'last_location_label' => 'Archived because no verified live tracking provider was attached.',
                ]);
        }
    }

    public function down(): void
    {
        // These normalizations intentionally do not recreate ambiguous unit
        // defaults or reactivate non-live tracking records.
    }
};
