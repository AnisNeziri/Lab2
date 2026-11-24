<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('exchange_snapshot')->nullable();
        });
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->json('exchange_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', fn (Blueprint $table) => $table->dropColumn('exchange_snapshot'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('exchange_snapshot'));
    }
};
