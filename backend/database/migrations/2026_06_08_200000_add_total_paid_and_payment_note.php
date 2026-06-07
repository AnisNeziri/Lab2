<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'total_paid')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->decimal('total_paid', 12, 2)->default(0)->after('total_amount');
            });
        }

        if (! Schema::hasColumn('payment_transactions', 'note')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->string('note')->nullable()->after('transaction_ref');
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('total_paid');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
