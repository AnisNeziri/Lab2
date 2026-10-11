<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scale profiles: dashboard sums/date aggregation and replenishment usage dominate scans.
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->index(['company_id', 'type', 'quantity'], 'pr2_stock_totals_idx');
            $t->index(['company_id', 'movement_code', 'type', 'occurred_at'], 'pr2_stock_usage_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropIndex('pr2_stock_totals_idx');
            $t->dropIndex('pr2_stock_usage_idx');
        });
    }
};
