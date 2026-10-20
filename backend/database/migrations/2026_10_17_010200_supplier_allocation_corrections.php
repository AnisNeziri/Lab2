<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payment_allocation_requests', function (Blueprint $t) {
            $t->date('reversal_date')->nullable();
        });
        Schema::table('purchase_order_payments', function (Blueprint $t) {
            $t->date('exchange_rate_date')->nullable();
            $t->string('exchange_rate_source', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_payments', function (Blueprint $t) {
            $t->dropColumn(['exchange_rate_date', 'exchange_rate_source']);
        });
        Schema::table('supplier_payment_allocation_requests', function (Blueprint $t) {
            $t->dropColumn('reversal_date');
        });
    }
};
