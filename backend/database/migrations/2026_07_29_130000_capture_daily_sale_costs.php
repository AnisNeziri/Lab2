<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_sale_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
            $table->decimal('cost_total', 12, 2)->nullable()->after('line_total');
            $table->decimal('gross_profit', 12, 2)->nullable()->after('cost_total');
        });

        // Existing rows intentionally stay NULL. Their historical purchase
        // cost was never captured, and copying today's product cost into an
        // older sale would turn an estimate into a misleading accounting fact.
        // Analytics reports that revenue as uncosted until new snapshot-backed
        // sales are recorded.
    }

    public function down(): void
    {
        Schema::table('daily_sale_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'cost_total', 'gross_profit']);
        });
    }
};
