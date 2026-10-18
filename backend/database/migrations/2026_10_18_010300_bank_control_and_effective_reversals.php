<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $reversalTables = ['financial_account_transactions', 'payment_transactions', 'expense_payments', 'expenses', 'purchase_order_payments'];

    public function up(): void
    {
        foreach ($this->reversalTables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->date('reversal_date')->nullable();
            });
        }
        Schema::table('bank_statements', function (Blueprint $t) {
            $t->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reopened_at')->nullable();
            $t->text('reopen_reason')->nullable();
        });
        Schema::table('bank_statement_rows', function (Blueprint $t) {
            $t->char('source_fingerprint', 64)->nullable()->index('dc2_bank_row_fingerprint_idx');
            $t->foreignId('duplicate_of_row_id')->nullable()->constrained('bank_statement_rows')->restrictOnDelete();
            $t->foreignId('duplicate_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('duplicate_reviewed_at')->nullable();
            $t->text('duplicate_review_reason')->nullable();
        });
        Schema::create('bank_statement_matches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bank_statement_row_id')->constrained()->restrictOnDelete();
            $t->foreignId('financial_account_transaction_id')->constrained('financial_account_transactions', 'id', 'dc2_bank_match_transaction_fk')->restrictOnDelete();
            // Null on release keeps history while guaranteeing one live owner.
            $t->foreignId('active_transaction_id')->nullable()->constrained('financial_account_transactions', 'id', 'dc2_bank_match_active_fk')->restrictOnDelete();
            $t->unique('active_transaction_id', 'dc2_bank_match_active_unique');
            $t->decimal('signed_amount', 18, 2);
            $t->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('matched_at');
            $t->timestamp('released_at')->nullable();
            $t->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('release_reason')->nullable();
            $t->boolean('legacy_match')->default(false);
            $t->index(['company_id', 'bank_statement_row_id'], 'dc2_bank_match_row_idx');
        });
        // Preserve unique legacy ownership. Existing duplicate/cross-company
        // links remain untouched and must be investigated by Integrity.
        $unique = DB::table('bank_statement_rows')->where('status', 'reconciled')->whereNotNull('matched_transaction_id')->groupBy('matched_transaction_id')->havingRaw('COUNT(*)=1')->select('matched_transaction_id');
        DB::table('bank_statement_rows as r')->join('bank_statements as s', 's.id', '=', 'r.bank_statement_id')->join('financial_account_transactions as f', 'f.id', '=', 'r.matched_transaction_id')
            ->where('r.status', 'reconciled')->whereIn('r.matched_transaction_id', $unique)->whereColumn('r.company_id', 's.company_id')->whereColumn('r.company_id', 'f.company_id')->whereColumn('s.financial_account_id', 'f.financial_account_id')
            ->select('r.id', 'r.company_id', 'r.matched_transaction_id', 'r.amount', 'r.reviewed_by', 'r.reviewed_at')->orderBy('r.id')->chunkById(200, function ($rows) {
                $values = [];
                foreach ($rows as $r) {
                    $values[] = ['company_id' => $r->company_id, 'bank_statement_row_id' => $r->id, 'financial_account_transaction_id' => $r->matched_transaction_id, 'active_transaction_id' => $r->matched_transaction_id, 'signed_amount' => $r->amount, 'matched_by' => $r->reviewed_by, 'matched_at' => $r->reviewed_at ?? now(), 'legacy_match' => true];
                }
                DB::table('bank_statement_matches')->insert($values);
            }, 'r.id', 'id');
        app(RolePermissionSeeder::class)->run(true);
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_matches');
        Schema::table('bank_statement_rows', function (Blueprint $t) {
            $t->dropForeign(['duplicate_of_row_id']);
            $t->dropForeign(['duplicate_reviewed_by']);
            $t->dropIndex('dc2_bank_row_fingerprint_idx');
            $t->dropColumn(['source_fingerprint', 'duplicate_of_row_id', 'duplicate_reviewed_by', 'duplicate_reviewed_at', 'duplicate_review_reason']);
        });
        Schema::table('bank_statements', function (Blueprint $t) {
            $t->dropForeign(['reopened_by']);
            $t->dropColumn(['reopened_by', 'reopened_at', 'reopen_reason']);
        });
        foreach ($this->reversalTables as $table) {
            Schema::table($table,function (Blueprint $t) {
                $t->dropColumn('reversal_date');
            });
        }
    }
};
