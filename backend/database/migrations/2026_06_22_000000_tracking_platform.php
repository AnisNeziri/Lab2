<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('tracking_number')->nullable()->after('company_id');
            $table->string('transport_mode')->default('sea')->after('tracking_number');
            $table->string('carrier')->nullable()->after('transport_mode');
            $table->boolean('is_favorite')->default(false)->after('is_saved');
            $table->json('route_waypoints')->nullable()->after('notification_state');
            $table->timestamp('last_refreshed_at')->nullable()->after('route_waypoints');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['company_id', 'tracking_number']);
            $table->index(['company_id', 'archived_at', 'is_favorite']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'tracking_number']);
            $table->dropIndex(['company_id', 'archived_at', 'is_favorite']);
            $table->dropColumn([
                'tracking_number',
                'transport_mode',
                'carrier',
                'is_favorite',
                'route_waypoints',
                'last_refreshed_at',
            ]);
        });
    }
};
