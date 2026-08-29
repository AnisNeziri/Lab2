<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('financial_accounts')) {
            Schema::create('financial_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('type', 20);
                $table->string('name', 120);
                $table->string('currency', 3)->default('EUR');
                $table->string('bank_name')->nullable();
                $table->string('account_number')->nullable();
                $table->decimal('opening_balance', 18, 2)->default(0);
                $table->date('opening_date')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'name'], 'financial_account_company_name_unique');
                $table->index(['company_id', 'type', 'is_active'], 'financial_account_lookup_idx');
            });
        }

        if (! Schema::hasTable('financial_account_transactions')) {
            Schema::create('financial_account_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
                $table->string('type', 30);
                $table->decimal('amount', 18, 2);
                $table->string('currency', 3)->default('EUR');
                $table->date('transaction_date');
                $table->string('source_type', 50)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->foreignId('related_transaction_id')->nullable()->constrained('financial_account_transactions')->nullOnDelete();
                $table->string('counterparty')->nullable();
                $table->string('reference_number')->nullable();
                $table->text('description')->nullable();
                $table->string('status', 20)->default('posted');
                // Internal ledger postings derive stable suffixes from the
                // originating request key (for example "-ledger"/"-refund").
                $table->string('idempotency_key', 100);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reversal_reason')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'idempotency_key'], 'financial_transaction_idempotency_unique');
                $table->index(['company_id', 'financial_account_id', 'transaction_date'], 'financial_transaction_account_date_idx');
                $table->index(['company_id', 'source_type', 'source_id'], 'financial_transaction_source_idx');
            });
        }

        if (! Schema::hasTable('financial_account_transfers')) {
            Schema::create('financial_account_transfers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('source_account_id')->constrained('financial_accounts')->restrictOnDelete();
                $table->foreignId('destination_account_id')->constrained('financial_accounts')->restrictOnDelete();
                $table->decimal('amount', 18, 2);
                $table->date('transfer_date');
                $table->string('reference_number')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('idempotency_key');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['company_id', 'idempotency_key'], 'financial_transfer_idempotency_unique');
            });
        }

        if (! Schema::hasTable('bank_statements')) {
            Schema::create('bank_statements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
                $table->string('file_name');
                $table->string('file_hash', 64);
                $table->json('column_mapping');
                $table->date('period_from')->nullable();
                $table->date('period_to')->nullable();
                $table->decimal('closing_balance', 18, 2)->nullable();
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['company_id', 'financial_account_id', 'file_hash'], 'bank_statement_file_unique');
            });
        }

        if (! Schema::hasTable('bank_statement_rows')) {
            Schema::create('bank_statement_rows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
                $table->date('transaction_date');
                $table->text('description')->nullable();
                $table->string('reference_number')->nullable();
                $table->decimal('amount', 18, 2);
                $table->decimal('statement_balance', 18, 2)->nullable();
                $table->string('status', 20)->default('unmatched');
                $table->foreignId('matched_transaction_id')->nullable()->constrained('financial_account_transactions')->nullOnDelete();
                $table->decimal('match_score', 5, 2)->nullable();
                $table->text('ignore_reason')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('row_hash', 64);
                $table->json('raw_data')->nullable();
                $table->timestamps();
                $table->unique(['bank_statement_id', 'row_hash'], 'bank_statement_row_unique');
                $table->index(['company_id', 'status', 'transaction_date'], 'bank_statement_row_status_idx');
            });
        }

        if (! Schema::hasTable('bank_reconciliation_events')) {
            Schema::create('bank_reconciliation_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('bank_statement_row_id')->constrained()->cascadeOnDelete();
                // Keep the explicit name below MySQL's 64-character identifier limit.
                $table->foreignId('financial_account_transaction_id')->nullable();
                $table->foreign(
                    'financial_account_transaction_id',
                    'bank_recon_event_fin_tx_fk'
                )->references('id')->on('financial_account_transactions')->nullOnDelete();
                $table->string('action', 30);
                $table->text('reason')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('expenses', 'purchase_order_id')) {
                $table->foreignId('purchase_order_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('expenses', 'original_expense_id')) {
                $table->foreignId('original_expense_id')->nullable()->after('purchase_order_id')
                    ->constrained('expenses')->nullOnDelete();
            }
            if (! Schema::hasColumn('expenses', 'match_status')) {
                $table->string('match_status', 30)->nullable()->after('status');
            }
            if (! Schema::hasColumn('expenses', 'match_summary')) {
                $table->json('match_summary')->nullable()->after('match_status');
            }
            if (! Schema::hasColumn('expenses', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('match_summary');
            }
            if (! Schema::hasColumn('expenses', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('supplier_invoice_items')) {
            Schema::create('supplier_invoice_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('goods_receipt_item_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->string('description');
                $table->decimal('quantity', 18, 3);
                $table->string('unit', 30);
                $table->decimal('unit_price', 18, 4);
                $table->decimal('vat_rate', 8, 3)->default(0);
                $table->decimal('net_amount', 18, 2);
                $table->decimal('vat_amount', 18, 2)->default(0);
                $table->decimal('total_amount', 18, 2);
                $table->json('variance')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'expense_id'], 'supplier_invoice_item_lookup_idx');
            });
        }

        if (! Schema::hasTable('expense_goods_receipts')) {
            Schema::create('expense_goods_receipts', function (Blueprint $table) {
                $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
                $table->foreignId('goods_receipt_id')->constrained()->restrictOnDelete();
                $table->primary(['expense_id', 'goods_receipt_id'], 'expense_goods_receipt_primary');
            });
        }

        if (! Schema::hasTable('supplier_match_events')) {
            Schema::create('supplier_match_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
                $table->string('action', 40);
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->text('reason')->nullable();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('inventory_returns')) {
            Schema::create('inventory_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('return_number');
                $table->string('type', 20);
                $table->string('status', 20)->default('draft');
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('daily_sale_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
                $table->string('financial_resolution', 30)->default('none');
                $table->decimal('financial_amount', 18, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->string('payment_method')->nullable();
                $table->string('reference_number')->nullable();
                $table->text('reason');
                $table->text('notes')->nullable();
                $table->uuid('idempotency_key');
                $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('financial_account_transaction_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('customer_debt_transaction_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('supplier_credit_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'return_number'], 'inventory_return_number_unique');
                $table->unique(['company_id', 'idempotency_key'], 'inventory_return_idempotency_unique');
                $table->index(['company_id', 'type', 'status'], 'inventory_return_status_idx');
            });
        }

        if (! Schema::hasTable('inventory_return_items')) {
            Schema::create('inventory_return_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_return_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->foreignId('daily_sale_item_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('goods_receipt_item_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
                $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
                $table->decimal('quantity', 18, 3);
                $table->decimal('processed_quantity', 18, 3)->default(0);
                $table->string('unit', 30);
                $table->string('condition', 20);
                $table->string('stock_state', 20)->nullable();
                $table->decimal('unit_cost', 18, 6)->nullable();
                $table->decimal('line_value', 18, 2)->nullable();
                $table->json('trace_data')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['inventory_return_id', 'product_id'], 'inventory_return_item_lookup_idx');
            });
        }

        if (! Schema::hasTable('inventory_return_events')) {
            Schema::create('inventory_return_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('inventory_return_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action', 40);
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        foreach (['payment_transactions', 'purchase_order_payments', 'expense_payments', 'customer_debt_transactions'] as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (! Schema::hasColumn($tableName, 'financial_account_id')) {
                        $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
                    }
                    if (! Schema::hasColumn($tableName, 'financial_account_transaction_id')) {
                        $table->foreignId('financial_account_transaction_id')->nullable();
                        $constraint = $tableName === 'customer_debt_transactions'
                            ? 'customer_debt_fin_account_tx_fk'
                            : null;
                        $table->foreign('financial_account_transaction_id', $constraint)
                            ->references('id')->on('financial_account_transactions')->nullOnDelete();
                    }
                });
            }
        }

        Schema::table('purchase_order_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_payments', 'expense_id')) {
                $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        foreach (['payment_transactions', 'purchase_order_payments', 'expense_payments', 'customer_debt_transactions'] as $tableName) {
            if (! Schema::hasTable($tableName)) continue;
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['financial_account_transaction_id', 'financial_account_id'] as $column) {
                    if (! Schema::hasColumn($tableName, $column)) continue;
                    if ($tableName === 'customer_debt_transactions' && $column === 'financial_account_transaction_id') {
                        $table->dropForeign('customer_debt_fin_account_tx_fk');
                        $table->dropColumn($column);
                    } else {
                        $table->dropConstrainedForeignId($column);
                    }
                }
            });
        }
        if (Schema::hasColumn('purchase_order_payments', 'expense_id')) {
            Schema::table('purchase_order_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('expense_id'));
        }
        Schema::dropIfExists('inventory_return_events');
        Schema::dropIfExists('inventory_return_items');
        Schema::dropIfExists('inventory_returns');
        Schema::dropIfExists('supplier_match_events');
        Schema::dropIfExists('expense_goods_receipts');
        Schema::dropIfExists('supplier_invoice_items');
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                foreach (['approved_by', 'original_expense_id', 'purchase_order_id', 'supplier_id'] as $column) {
                    if (Schema::hasColumn('expenses', $column)) $table->dropConstrainedForeignId($column);
                }
                foreach (['approved_at', 'match_summary', 'match_status'] as $column) {
                    if (Schema::hasColumn('expenses', $column)) $table->dropColumn($column);
                }
            });
        }
        Schema::dropIfExists('bank_reconciliation_events');
        Schema::dropIfExists('bank_statement_rows');
        Schema::dropIfExists('bank_statements');
        Schema::dropIfExists('financial_account_transfers');
        Schema::dropIfExists('financial_account_transactions');
        Schema::dropIfExists('financial_accounts');
    }
};
