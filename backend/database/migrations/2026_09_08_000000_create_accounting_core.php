<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->date('accounting_start_date')->nullable();
        });

        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30); $table->string('name', 160); $table->string('type', 20);
            $table->foreignId('parent_id')->nullable()->constrained('accounting_accounts')->restrictOnDelete();
            $table->string('normal_balance', 6); $table->boolean('is_posting')->default(true);
            $table->boolean('is_system')->default(false); $table->boolean('is_active')->default(true);
            $table->string('cash_flow_class', 20)->nullable(); $table->text('description')->nullable();
            $table->timestamps(); $table->unique(['company_id','code']);
            $table->index(['company_id','type','is_active']);
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name'); $table->date('starts_at'); $table->date('ends_at');
            $table->string('status', 20)->default('open'); $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable(); $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable(); $table->text('change_reason')->nullable(); $table->timestamps();
            $table->unique(['company_id','starts_at','ends_at'], 'accounting_period_unique');
        });

        Schema::create('accounting_posting_mappings', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('mapping_key', 80); $table->foreignId('accounting_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
            $table->unique(['company_id','mapping_key']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('journal_number', 40); $table->date('posting_date'); $table->string('reference_number')->nullable();
            $table->text('description'); $table->string('source_module', 50); $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable(); $table->string('source_key', 160);
            $table->char('currency', 3); $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('total_debit', 18, 2)->default(0); $table->decimal('total_credit', 18, 2)->default(0);
            $table->string('status', 20)->default('draft'); $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable(); $table->timestamps();
            $table->unique(['company_id','journal_number']); $table->unique(['company_id','source_module','source_key'], 'journal_source_unique');
            $table->index(['company_id','status','posting_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id(); $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accounting_account_id')->constrained()->restrictOnDelete(); $table->unsignedSmallInteger('line_number');
            $table->text('description')->nullable(); $table->decimal('debit', 18, 2)->default(0); $table->decimal('credit', 18, 2)->default(0);
            $table->decimal('foreign_debit', 18, 2)->default(0); $table->decimal('foreign_credit', 18, 2)->default(0);
            $table->string('counterparty_type', 30)->nullable(); $table->unsignedBigInteger('counterparty_id')->nullable(); $table->timestamps();
            $table->unique(['journal_entry_id','line_number']); $table->index(['accounting_account_id','journal_entry_id']);
        });

        Schema::create('accounting_exceptions', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('exception_key', 190); $table->string('type', 60); $table->string('severity', 20)->default('warning');
            $table->text('message'); $table->json('details')->nullable(); $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('resolved_at')->nullable(); $table->text('resolution')->nullable();
            $table->timestamps(); $table->unique(['company_id','exception_key']); $table->index(['company_id','status','severity']);
        });

        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->foreignId('accounting_account_id')->nullable()->constrained('accounting_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', fn (Blueprint $table) => $table->dropConstrainedForeignId('accounting_account_id'));
        Schema::dropIfExists('accounting_exceptions'); Schema::dropIfExists('journal_lines'); Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounting_posting_mappings'); Schema::dropIfExists('accounting_periods'); Schema::dropIfExists('accounting_accounts');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('accounting_start_date'));
    }
};
