<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_debt_transactions', function (Blueprint $table) {
            $table->foreignId('reversed_transaction_id')->nullable()->after('daily_sale_id')->constrained('customer_debt_transactions')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable()->after('reference_number');
            $table->date('due_date')->nullable()->after('transaction_date');
            $table->string('source')->default('manual')->after('type');
            $table->unique(['company_id', 'idempotency_key'], 'debt_tx_company_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_debt_transactions', function (Blueprint $table) {
            $table->dropUnique('debt_tx_company_idempotency_unique');
            $table->dropConstrainedForeignId('reversed_transaction_id');
            $table->dropColumn(['idempotency_key', 'due_date', 'source']);
        });
    }
};
