<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('vendor_name');
            $table->string('vendor_business_number', 50)->nullable();
            $table->string('vendor_fiscal_number', 50)->nullable();
            $table->string('vendor_vat_number', 50)->nullable();
            $table->char('vendor_key', 64);
            $table->string('document_type', 30);
            $table->string('document_number', 100);
            $table->string('document_number_normalized', 120);
            $table->string('original_document_number', 100)->nullable();
            $table->string('source_type', 20)->default('domestic');
            $table->string('asset_treatment', 20)->default('ordinary');
            $table->string('category', 40)->default('other');
            $table->text('description')->nullable();
            $table->text('business_purpose')->nullable();
            $table->date('invoice_date');
            $table->date('received_date');
            $table->date('supply_date')->nullable();
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->date('exchange_rate_date')->nullable();
            $table->string('exchange_rate_source')->nullable();
            $table->decimal('net_amount', 15, 2);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('self_assessed_vat_amount', 15, 2)->default(0);
            $table->decimal('gross_amount', 15, 2);
            $table->string('vat_treatment', 30)->default('standard');
            $table->boolean('input_vat_eligible')->default(false);
            $table->decimal('deductible_vat_amount', 15, 2)->default(0);
            $table->decimal('non_deductible_vat_amount', 15, 2)->default(0);
            $table->decimal('net_amount_eur', 15, 2);
            $table->decimal('vat_amount_eur', 15, 2)->default(0);
            $table->decimal('self_assessed_vat_amount_eur', 15, 2)->default(0);
            $table->decimal('gross_amount_eur', 15, 2);
            $table->decimal('deductible_vat_amount_eur', 15, 2)->default(0);
            $table->decimal('non_deductible_vat_amount_eur', 15, 2)->default(0);
            $table->text('tax_legal_reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->char('vat_period', 7)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->date('retention_until')->nullable();
            $table->string('proof_filename')->nullable();
            $table->string('proof_mime', 100)->nullable();
            $table->unsignedBigInteger('proof_size')->nullable();
            $table->char('proof_sha256', 64)->nullable();
            // Laravel's portable binary type maps to BLOB on SQLite. MySQL is
            // widened below so a normal scanned invoice can exceed 64 KiB.
            $table->binary('proof_data')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['company_id', 'vendor_key', 'document_number_normalized'],
                'expense_company_vendor_document_unique'
            );
            $table->index(['company_id', 'invoice_date'], 'expense_company_invoice_date_idx');
            $table->index(['company_id', 'status', 'invoice_date'], 'expense_company_status_date_idx');
            $table->index(['company_id', 'vat_period'], 'expense_company_vat_period_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE expenses MODIFY proof_data LONGBLOB NULL');
        }

        Schema::create('expense_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('amount_eur', 15, 2);
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->date('exchange_rate_date')->nullable();
            $table->string('exchange_rate_source')->nullable();
            $table->string('status', 20)->default('completed');
            $table->date('payment_date');
            $table->string('payment_method', 30)->default('cash');
            $table->string('reference_number')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'expense_payment_company_idempotency_unique');
            $table->index(['company_id', 'payment_date'], 'expense_payment_company_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
        Schema::dropIfExists('expenses');
    }
};
