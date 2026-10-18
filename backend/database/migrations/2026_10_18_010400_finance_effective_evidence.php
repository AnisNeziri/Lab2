<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payment_allocation_requests', function (Blueprint $t) {
            $t->date('allocation_date')->nullable()->index();
        });
        Schema::table('landed_cost_accounting_entries', function (Blueprint $t) {
            $t->date('reversal_date')->nullable();
            $t->decimal('reversal_inventory_value_before', 20, 6)->nullable();
            $t->decimal('reversal_inventory_value_after', 20, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payment_allocation_requests', fn (Blueprint $t) => $t->dropColumn('allocation_date'));
        Schema::table('landed_cost_accounting_entries', fn (Blueprint $t) => $t->dropColumn(['reversal_date', 'reversal_inventory_value_before', 'reversal_inventory_value_after']));
    }
};
