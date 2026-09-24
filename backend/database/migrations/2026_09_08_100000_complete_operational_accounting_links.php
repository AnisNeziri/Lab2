<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('accounting_opening_finalized_at')->nullable()->after('accounting_start_date');
            $table->unsignedBigInteger('accounting_opening_journal_id')->nullable()->after('accounting_opening_finalized_at');
        });

        Schema::table('financial_account_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_entry_id')->nullable()->after('related_transaction_id');
            $table->decimal('exchange_rate', 18, 8)->nullable()->after('currency');
            $table->date('exchange_rate_date')->nullable()->after('exchange_rate');
            $table->string('exchange_rate_source')->nullable()->after('exchange_rate_date');
            $table->index(['company_id', 'journal_entry_id']);
        });

        Schema::table('bank_statement_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_entry_id')->nullable()->after('matched_transaction_id');
            $table->index(['company_id', 'journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_rows', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'journal_entry_id']);
            $table->dropColumn(['journal_entry_id', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source']);
        });
        Schema::table('financial_account_transactions', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'journal_entry_id']);
            $table->dropColumn('journal_entry_id');
        });
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn([
            'accounting_opening_finalized_at', 'accounting_opening_journal_id',
        ]));
    }
};
