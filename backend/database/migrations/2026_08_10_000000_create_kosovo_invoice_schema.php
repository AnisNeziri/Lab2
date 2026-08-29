<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('business_registration_number')->nullable();
            $table->string('fiscal_number')->nullable();
            $table->boolean('is_vat_registered')->default(false);
            $table->string('vat_number')->nullable();
            $table->text('registered_address');
            $table->string('municipality')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->default('XK');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('swift_bic', 11)->nullable();
            $table->string('invoice_prefix', 20)->default('INV');
            $table->string('credit_note_prefix', 20)->default('CN');
            $table->string('default_language', 20)->default('bilingual');
            $table->unsignedSmallInteger('default_payment_terms_days')->default(14);
            $table->text('default_payment_terms')->nullable();
            $table->string('sales_mode', 30)->default('business_only');
            $table->timestamps();
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 30);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(
                ['company_id', 'document_type', 'year'],
                'invoice_sequence_company_type_year_unique'
            );
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_type', 20)->default('business');
            $table->string('business_registration_number')->nullable();
            $table->string('fiscal_number')->nullable();
            $table->boolean('is_vat_registered')->default(false);
            $table->string('vat_number')->nullable();
            $table->text('billing_address')->nullable();
            $table->string('municipality')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->default('XK');
            $table->index(
                ['company_id', 'business_registration_number'],
                'customer_company_business_number_idx'
            );
            $table->index(['company_id', 'fiscal_number'], 'customer_company_fiscal_number_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->default(18);
            $table->string('tax_treatment', 30)->default('standard');
            $table->text('tax_legal_reference')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('daily_sale_id')->nullable()->constrained('daily_sales')->nullOnDelete();
            $table->string('document_type', 30)->default('invoice');
            $table->foreignId('original_invoice_id')
                ->nullable()
                ->constrained('invoices')
                ->nullOnDelete();
            $table->text('credit_reason')->nullable();
            $table->json('seller_snapshot')->nullable();
            $table->json('buyer_snapshot')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->date('invoice_date')->nullable();
            $table->date('supply_date')->nullable();
            $table->time('supply_time')->nullable();
            $table->text('payment_terms')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('taxable_total', 15, 2)->default(0);
            $table->decimal('vat_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->string('payment_status', 30)->default('unpaid');
            $table->unsignedSmallInteger('sequence_year')->nullable();
            $table->unsignedBigInteger('sequence_number')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamp('stock_applied_at')->nullable();
            $table->timestamp('stock_reversed_at')->nullable();
            $table->char('integrity_hash', 64)->nullable();
            $table->string('fiscal_receipt_number')->nullable();
            $table->string('external_fiscal_code')->nullable();
            $table->date('retention_until')->nullable();
            $table->string('compliance_status', 30)->default('draft');
            $table->text('notes')->nullable();

            $table->unique(
                ['company_id', 'document_type', 'sequence_year', 'sequence_number'],
                'invoice_company_type_year_sequence_unique'
            );
            $table->unique(
                ['company_id', 'daily_sale_id'],
                'invoice_company_daily_sale_unique'
            );
            $table->index(['company_id', 'invoice_date'], 'invoice_company_date_idx');
            $table->index(['company_id', 'customer_id'], 'invoice_company_customer_idx');
            $table->index(
                ['company_id', 'payment_status', 'due_at'],
                'invoice_company_payment_due_idx'
            );
            $table->index(
                ['company_id', 'compliance_status'],
                'invoice_company_compliance_idx'
            );
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->change();
            $table->dateTime('issued_at')->nullable()->change();
            $table->decimal('total_amount', 15, 2)->default(0)->change();
            $table->decimal('total_paid', 15, 2)->default(0)->change();
        });

        // Some older installations swallowed the original tenant-index
        // migration error and retained a global invoice-number unique key.
        // Remove that key only when present, and add the tenant key only when
        // no equivalent unique index already exists.
        foreach (Schema::getIndexes('invoices') as $index) {
            if (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['invoice_number']) {
                Schema::table('invoices', function (Blueprint $table) use ($index) {
                    $table->dropUnique($index['name']);
                });
            }
        }

        $hasTenantNumberUnique = collect(Schema::getIndexes('invoices'))->contains(
            fn (array $index): bool => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['company_id', 'invoice_number']
        );
        if (! $hasTenantNumberUnique) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unique(['company_id', 'invoice_number'], 'invoice_company_number_unique_v2');
            });
        }

        DB::table('invoices')
            ->select(['id', 'issued_at', 'due_at', 'total_amount', 'total_paid'])
            ->orderBy('id')
            ->chunkById(500, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $total = (string) ($invoice->total_amount ?? '0');
                    $paid = (float) ($invoice->total_paid ?? 0);
                    $amount = (float) ($invoice->total_amount ?? 0);

                    DB::table('invoices')->where('id', $invoice->id)->update([
                        'invoice_date' => $invoice->issued_at,
                        'subtotal' => $total,
                        'taxable_total' => $total,
                        'grand_total' => $total,
                        'payment_status' => $amount > 0 && $paid >= $amount
                            ? 'paid'
                            : ($paid > 0 ? 'partially_paid' : 'unpaid'),
                        'compliance_status' => 'legacy',
                    ]);
                }
            });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('sku_snapshot')->nullable();
            $table->string('unit', 50)->default('pcs');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('taxable_amount', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->string('tax_treatment', 30)->default('standard');
            $table->text('tax_legal_reference')->nullable();
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('cost_total', 15, 2)->nullable();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->text('description')->change();
            $table->decimal('unit_price', 15, 4)->change();
            $table->decimal('line_total', 15, 2)->change();
        });

        DB::table('invoice_items')->update([
            'taxable_amount' => DB::raw('line_total'),
        ]);

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('payment_date')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->unique(
                ['company_id', 'idempotency_key'],
                'payment_company_idempotency_unique'
            );
            $table->index(['company_id', 'payment_date'], 'payment_company_date_idx');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->change();
            $table->string('transaction_ref')->nullable()->change();
        });

        DB::table('payment_transactions')
            ->whereNull('payment_date')
            ->update(['payment_date' => DB::raw('COALESCE(paid_at, created_at)')]);

        Schema::table('customer_debt_transactions', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_transaction_id')
                ->nullable()
                ->constrained('payment_transactions')
                ->nullOnDelete();
            $table->index(['company_id', 'invoice_id'], 'debt_tx_company_invoice_idx');
            $table->index(
                ['company_id', 'payment_transaction_id'],
                'debt_tx_company_payment_idx'
            );
        });
    }

    public function down(): void
    {
        $hasV2TenantIndex = collect(Schema::getIndexes('invoices'))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === 'invoice_company_number_unique_v2'
        );
        if ($hasV2TenantIndex) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropUnique('invoice_company_number_unique_v2');
            });
        }
        Schema::table('customer_debt_transactions', function (Blueprint $table) {
            $table->dropIndex('debt_tx_company_payment_idx');
            $table->dropIndex('debt_tx_company_invoice_idx');
            $table->dropConstrainedForeignId('payment_transaction_id');
            $table->dropConstrainedForeignId('invoice_id');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex('payment_company_date_idx');
            $table->dropUnique('payment_company_idempotency_unique');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn([
                'payment_date',
                'reference_number',
                'idempotency_key',
                'reversed_at',
                'reversal_reason',
            ]);
        });

        DB::table('payment_transactions')
            ->whereNull('transaction_ref')
            ->orderBy('id')
            ->eachById(function ($payment): void {
                DB::table('payment_transactions')->where('id', $payment->id)->update([
                    'transaction_ref' => 'ROLLBACK-PAY-'.$payment->id,
                ]);
            });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->change();
            $table->string('transaction_ref')->nullable(false)->change();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn([
                'sku_snapshot',
                'unit',
                'discount_percent',
                'discount_amount',
                'taxable_amount',
                'vat_rate',
                'vat_amount',
                'tax_treatment',
                'tax_legal_reference',
                'unit_cost',
                'cost_total',
            ]);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('description')->change();
            $table->decimal('unit_price', 10, 2)->change();
            $table->decimal('line_total', 12, 2)->change();
        });

        DB::table('invoices')->whereNull('invoice_number')->orderBy('id')->eachById(
            function ($invoice): void {
                DB::table('invoices')->where('id', $invoice->id)->update([
                    'invoice_number' => 'ROLLBACK-'.$invoice->id,
                    'issued_at' => $invoice->issued_at ?? now()->toDateString(),
                ]);
            }
        );

        DB::table('invoices')->whereNull('issued_at')->update([
            'issued_at' => now()->toDateString(),
        ]);

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoice_company_compliance_idx');
            $table->dropIndex('invoice_company_payment_due_idx');
            $table->dropIndex('invoice_company_customer_idx');
            $table->dropIndex('invoice_company_date_idx');
            $table->dropUnique('invoice_company_daily_sale_unique');
            $table->dropUnique('invoice_company_type_year_sequence_unique');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropConstrainedForeignId('issued_by');
            $table->dropConstrainedForeignId('original_invoice_id');
            $table->dropConstrainedForeignId('daily_sale_id');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn([
                'document_type',
                'credit_reason',
                'seller_snapshot',
                'buyer_snapshot',
                'currency',
                'invoice_date',
                'supply_date',
                'supply_time',
                'payment_terms',
                'payment_method',
                'subtotal',
                'discount_total',
                'taxable_total',
                'vat_total',
                'grand_total',
                'payment_status',
                'sequence_year',
                'sequence_number',
                'voided_at',
                'void_reason',
                'stock_applied_at',
                'stock_reversed_at',
                'integrity_hash',
                'fiscal_receipt_number',
                'external_fiscal_code',
                'retention_until',
                'compliance_status',
                'notes',
            ]);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number')->nullable(false)->change();
            $table->date('issued_at')->nullable(false)->change();
            $table->decimal('total_amount', 12, 2)->default(0)->change();
            $table->decimal('total_paid', 12, 2)->default(0)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'tax_treatment', 'tax_legal_reference']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customer_company_fiscal_number_idx');
            $table->dropIndex('customer_company_business_number_idx');
            $table->dropColumn([
                'customer_type',
                'business_registration_number',
                'fiscal_number',
                'is_vat_registered',
                'vat_number',
                'billing_address',
                'municipality',
                'postal_code',
                'country_code',
            ]);
        });

        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('invoice_profiles');
    }
};
