<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->decimal('origin_lat', 10, 7)->nullable()->change();
            $table->decimal('origin_lng', 10, 7)->nullable()->change();
            $table->decimal('destination_lat', 10, 7)->nullable()->change();
            $table->decimal('destination_lng', 10, 7)->nullable()->change();
        });

        DB::table('shipments')
            ->where(function ($query) {
                $query->where('tracking_provider', 'demo')
                    ->orWhere('tracking_mode', 'demo');
            })
            ->update([
                'tracking_provider' => 'manual',
                'tracking_mode' => 'manual',
                'current_lat' => null,
                'current_lng' => null,
                'distance_to_port_km' => null,
                'position_updated_at' => null,
                'speed_knots' => null,
                'course' => null,
                'last_location_label' => 'Manual shipment record — automatic tracking is not configured.',
            ]);
    }

    public function down(): void
    {
        DB::table('shipments')->whereNull('origin_lat')->update(['origin_lat' => 0]);
        DB::table('shipments')->whereNull('origin_lng')->update(['origin_lng' => 0]);
        DB::table('shipments')->whereNull('destination_lat')->update(['destination_lat' => 0]);
        DB::table('shipments')->whereNull('destination_lng')->update(['destination_lng' => 0]);

        Schema::table('shipments', function (Blueprint $table) {
            $table->decimal('origin_lat', 10, 7)->nullable(false)->change();
            $table->decimal('origin_lng', 10, 7)->nullable(false)->change();
            $table->decimal('destination_lat', 10, 7)->nullable(false)->change();
            $table->decimal('destination_lng', 10, 7)->nullable(false)->change();
        });
    }
};
