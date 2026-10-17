<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_accounts', function (Blueprint $t) {
            $t->string('statement_group', 40)->nullable();
        });
        // Classify existing defaults once. Codes remain company-configurable;
        // reports subsequently use this explicit classification, never prefixes.
        foreach (['4000' => 'revenue', '4200' => 'other_income', '5000' => 'cost_of_sales', '6300' => 'other_expense'] as $code => $group) {
            DB::table('accounting_accounts')->where('code', $code)->update(['statement_group' => $group]);
        }
        DB::table('accounting_accounts')->where('type', 'expense')->whereNull('statement_group')->update(['statement_group' => 'operating_expense']);
        DB::table('accounting_accounts')->where('type', 'revenue')->whereNull('statement_group')->update(['statement_group' => 'revenue']);
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->unsignedInteger('revision')->default(1);
            $t->json('control_acknowledgement')->nullable();
            $t->date('exchange_rate_date')->nullable();
            $t->string('exchange_rate_source', 160)->nullable();
            $t->foreignId('correction_of_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $t->index(['company_id', 'source_module', 'posting_date'], 'dc2_journal_source_date_idx');
            $t->index(['company_id', 'posted_by', 'posting_date'], 'dc2_journal_actor_date_idx');
        });
        Schema::table('journal_lines', fn (Blueprint $t) => $t->json('dimensions')->nullable());
        Schema::table('accounting_periods', fn (Blueprint $t) => $t->json('close_snapshot')->nullable());
        Schema::table('bank_statements', function (Blueprint $t) {
            $t->decimal('opening_balance', 18, 2)->nullable();
            $t->string('reconciliation_status', 20)->default('open');
            $t->timestamp('reconciled_at')->nullable();
            $t->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('reconciliation_proof')->nullable();
        });
        // New grants go only to explicitly privileged defaults. Preserve
        // installation-specific grants/revocations already set by the owner.
        app(RolePermissionSeeder::class)->run(true);
    }

    public function down(): void
    {
        Schema::table('bank_statements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reconciled_by');
            $t->dropColumn(['opening_balance', 'reconciliation_status', 'reconciled_at', 'reconciliation_proof']);
        });
        Schema::table('accounting_periods', fn (Blueprint $t) => $t->dropColumn('close_snapshot'));
        Schema::table('journal_lines', fn (Blueprint $t) => $t->dropColumn('dimensions'));
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->dropIndex('dc2_journal_source_date_idx');
            $t->dropIndex('dc2_journal_actor_date_idx');
            $t->dropConstrainedForeignId('correction_of_id');
            $t->dropColumn(['revision', 'control_acknowledgement', 'exchange_rate_date', 'exchange_rate_source']);
        });
        Schema::table('accounting_accounts', fn (Blueprint $t) => $t->dropColumn('statement_group'));
    }
};
