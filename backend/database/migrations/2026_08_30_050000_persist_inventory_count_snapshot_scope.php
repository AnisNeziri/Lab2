<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_count_sessions', function (Blueprint $table): void {
            $table->boolean('scope_all_products')->default(false)->after('stock_states');
            $table->json('scope_product_ids')->nullable()->after('scope_all_products');
            $table->json('snapshot_identity_keys')->nullable()->after('scope_product_ids');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_count_sessions', function (Blueprint $table): void {
            $table->dropColumn(['scope_all_products', 'scope_product_ids', 'snapshot_identity_keys']);
        });
    }
};
