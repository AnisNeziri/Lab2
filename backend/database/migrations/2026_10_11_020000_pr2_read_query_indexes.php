<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PR2 EXPLAIN evidence: NULL-reversal scans and history sorts dominate scale reads.
        Schema::table('customer_debt_transactions', function (Blueprint $t) {
            $t->index(['company_id', 'customer_id', 'reversed_transaction_id', 'type'], 'pr2_debt_customer_active_idx');
        });
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dateTime('effective_occurred_at')->virtualAs('COALESCE(occurred_at, created_at)');
            $t->index(['company_id', 'effective_occurred_at', 'id'], 'pr2_stock_effective_idx');
            $t->index(['company_id', 'created_at', 'id'], 'pr2_stock_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_debt_transactions', fn (Blueprint $t) => $t->dropIndex('pr2_debt_customer_active_idx'));
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropIndex('pr2_stock_effective_idx');
            $t->dropIndex('pr2_stock_created_idx');
            $t->dropColumn('effective_occurred_at');
        });
    }
};
