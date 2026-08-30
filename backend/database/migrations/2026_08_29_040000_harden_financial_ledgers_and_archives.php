<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $auditCompanyAddedHere = false;
        if (Schema::hasTable('audit_logs') && ! Schema::hasColumn('audit_logs', 'company_id')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
            $auditCompanyAddedHere = true;
            DB::table('audit_logs')->whereNull('company_id')->whereNotNull('user_id')->update([
                'company_id' => DB::raw('(SELECT company_id FROM users WHERE users.id = audit_logs.user_id)'),
            ]);
        }
        if (Schema::hasTable('audit_logs')) {
            // This migration owns this deliberately unique index name. A tiny
            // second marker is needed only on legacy databases where it also
            // had to add company_id, so down() never drops a baseline column.
            $auditIndex = 'audit_company_fin_hardening_idx';
            $hasAuditIndex = collect(Schema::getIndexes('audit_logs'))
                ->contains(fn (array $index): bool => ($index['name'] ?? null) === $auditIndex);
            if (! $hasAuditIndex) {
                Schema::table('audit_logs', fn (Blueprint $table) => $table->index(['company_id', 'created_at'], $auditIndex));
            }
            if ($auditCompanyAddedHere) {
                Schema::table('audit_logs', fn (Blueprint $table) => $table->index('company_id', 'audit_company_column_owner_idx'));
            }
        }

        Schema::table('shipment_histories', function (Blueprint $table): void {
            if (! Schema::hasColumn('shipment_histories', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('shipment_id')->constrained()->nullOnDelete();
            }
        });

        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'current_credit')) {
                $table->decimal('current_credit', 15, 2)->default(0)->after('current_debt');
            }
        });

        Schema::table('customer_debt_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_debt_transactions', 'credit_before')) {
                $table->decimal('credit_before', 15, 2)->default(0)->after('balance_after');
            }
            if (! Schema::hasColumn('customer_debt_transactions', 'credit_after')) {
                $table->decimal('credit_after', 15, 2)->default(0)->after('credit_before');
            }
        });

        // Older data could represent an overpayment as a negative debt. Preserve
        // the economic value while making the advance explicit and non-negative.
        DB::table('customers')->where('current_debt', '<', 0)->orderBy('id')->each(function (object $customer): void {
            $credit = number_format(abs((float) $customer->current_debt), 2, '.', '');
            DB::table('customers')->where('id', $customer->id)->update([
                'current_debt' => '0.00',
                'current_credit' => $credit,
            ]);
            $lastId = DB::table('customer_debt_transactions')
                ->where('customer_id', $customer->id)->orderByDesc('id')->value('id');
            if ($lastId) {
                DB::table('customer_debt_transactions')->where('id', $lastId)->update([
                    'balance_after' => '0.00',
                    'credit_after' => $credit,
                ]);
            }
        });

        $hasReversalIndex = collect(Schema::getIndexes('customer_debt_transactions'))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === 'debt_tx_single_reversal_unique');
        if (! $hasReversalIndex && ! DB::table('customer_debt_transactions')
            ->whereNotNull('reversed_transaction_id')
            ->select('reversed_transaction_id')
            ->groupBy('reversed_transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            Schema::table('customer_debt_transactions', function (Blueprint $table): void {
                $table->unique('reversed_transaction_id', 'debt_tx_single_reversal_unique');
            });
        }

        if (! Schema::hasTable('supplier_payment_allocation_requests')) {
            Schema::create('supplier_payment_allocation_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('supplier_invoice_payment_allocation_id')->nullable();
                $table->foreign('supplier_invoice_payment_allocation_id', 'spar_allocation_fk')
                    ->references('id')->on('supplier_invoice_payment_allocations')->nullOnDelete();
                $table->foreignId('purchase_order_payment_id');
                $table->foreign('purchase_order_payment_id', 'spar_payment_fk')
                    ->references('id')->on('purchase_order_payments')->restrictOnDelete();
                $table->foreignId('expense_id')->constrained()->restrictOnDelete();
                $table->decimal('amount', 15, 2);
                $table->string('idempotency_key', 100);
                $table->string('reason', 1000)->nullable();
                $table->string('status', 20)->default('completed');
                $table->timestamp('reversed_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reversal_reason')->nullable();
                $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
                // Older MySQL/MariaDB builds reject a required TIMESTAMP
                // without an explicit default when nullable timestamp fields
                // precede it. The service still supplies the exact event time.
                $table->timestamp('allocated_at')->useCurrent();
                $table->timestamps();
                $table->unique(['company_id', 'idempotency_key'], 'spar_company_idempotency_unique');
                $table->index(['company_id', 'expense_id'], 'spar_company_expense_idx');
            });
        }

        Schema::table('purchase_order_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_order_payments', 'status')) {
                $table->string('status', 20)->default('completed')->after('amount_eur');
                $table->timestamp('reversed_at')->nullable()->after('paid_at');
                $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
                $table->text('reversal_reason')->nullable()->after('reversed_by');
                $table->index(['company_id', 'status', 'payment_date'], 'po_payment_company_status_date_idx');
            }
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            if (! Schema::hasColumn('suppliers', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('address');
            }
            if (! Schema::hasColumn('suppliers', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('suppliers', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('suppliers', 'deleted_at')) {
                $table->softDeletes();
            }
            $hasIndex = collect(Schema::getIndexes('suppliers'))
                ->contains(fn (array $index): bool => ($index['name'] ?? null) === 'supplier_company_active_name_idx');
            if (! $hasIndex) {
                $table->index(['company_id', 'is_active', 'name'], 'supplier_company_active_name_idx');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_logs')) {
            $auditIndexes = collect(Schema::getIndexes('audit_logs'))->pluck('name');
            if ($auditIndexes->contains('audit_company_fin_hardening_idx')) {
                Schema::table('audit_logs', fn (Blueprint $table) => $table->dropIndex('audit_company_fin_hardening_idx'));
            }
            if ($auditIndexes->contains('audit_company_column_owner_idx')) {
                Schema::table('audit_logs', fn (Blueprint $table) => $table->dropIndex('audit_company_column_owner_idx'));
                Schema::table('audit_logs', fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
            }
        }

        Schema::table('shipment_histories', function (Blueprint $table): void {
            if (Schema::hasColumn('shipment_histories', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $hasIndex = collect(Schema::getIndexes('suppliers'))
                ->contains(fn (array $index): bool => ($index['name'] ?? null) === 'supplier_company_active_name_idx');
            if ($hasIndex) {
                $table->dropIndex('supplier_company_active_name_idx');
            }
            if (Schema::hasColumn('suppliers', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
            if (Schema::hasColumn('suppliers', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }
            if (Schema::hasColumn('suppliers', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
            if (Schema::hasColumn('suppliers', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });

        Schema::dropIfExists('supplier_payment_allocation_requests');

        Schema::table('purchase_order_payments', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_order_payments', 'status')) {
                $table->dropIndex('po_payment_company_status_date_idx');
                $table->dropConstrainedForeignId('reversed_by');
                $table->dropColumn(['status', 'reversed_at', 'reversal_reason']);
            }
        });

        $hasSingleReversalIndex = collect(Schema::getIndexes('customer_debt_transactions'))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === 'debt_tx_single_reversal_unique');
        if ($hasSingleReversalIndex) {
            Schema::table('customer_debt_transactions', fn (Blueprint $table) => $table->dropUnique('debt_tx_single_reversal_unique'));
        }
        Schema::table('customer_debt_transactions', function (Blueprint $table): void {
            foreach (['credit_before', 'credit_after'] as $column) {
                if (Schema::hasColumn('customer_debt_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'current_credit')) {
                $table->dropColumn('current_credit');
            }
        });
    }
};
