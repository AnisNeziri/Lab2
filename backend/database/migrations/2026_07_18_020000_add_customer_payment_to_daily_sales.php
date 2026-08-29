<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_sales', function (Blueprint $t) {
            $t->foreignId('customer_id')->nullable()->after('customer_name')->constrained()->nullOnDelete();
            $t->decimal('paid_amount', 12, 2)->default(0)->after('total_amount');
            $t->string('payment_method')->nullable()->after('paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('daily_sales', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_id');
            $t->dropColumn(['paid_amount', 'payment_method']);
        });
    }
};
