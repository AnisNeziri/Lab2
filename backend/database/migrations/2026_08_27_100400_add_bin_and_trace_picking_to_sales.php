<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_sale_items', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('warehouse_id')->constrained('warehouse_locations')->nullOnDelete();
            $table->json('trace_allocations')->nullable()->after('location_id');
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('warehouse_id')->constrained('warehouse_locations')->nullOnDelete();
            $table->json('trace_allocations')->nullable()->after('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn('trace_allocations');
        });
        Schema::table('daily_sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn('trace_allocations');
        });
    }
};
