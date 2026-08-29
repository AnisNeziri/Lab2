<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('quantity', 14, 3)->default(0)->change();
            $table->decimal('min_quantity', 14, 3)->default(0)->change();
            $table->decimal('high_stock_threshold', 14, 3)->default(0)->change();
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('quantity', 14, 3)->change();
            $table->decimal('quantity_before', 14, 3)->change();
            $table->decimal('quantity_after', 14, 3)->change();
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('quantity', 14, 3)->change();
        });
        Schema::table('daily_sales', function (Blueprint $table) {
            $table->decimal('total_quantity', 14, 3)->default(0)->change();
        });
        Schema::table('daily_sale_items', function (Blueprint $table) {
            $table->decimal('quantity', 14, 3)->change();
        });
    }

    public function down(): void {}
};
