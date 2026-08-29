<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('currency', 3)->default('EUR')->after('total_paid');
            $table->date('expected_at')->nullable()->after('ordered_at');
            $table->timestamp('cancelled_at')->nullable()->after('received_at');
            $table->foreignId('cancelled_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'status', 'ordered_at'], 'po_company_status_ordered_idx');
            $table->index(['company_id', 'due_at'], 'po_company_due_idx');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('unit', 50)->default('pcs')->after('description');
            $table->decimal('received_quantity', 14, 3)->default(0)->after('quantity');
        });

        Schema::table('purchase_order_payments', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->after('purchase_order_id')->constrained()->nullOnDelete();
            $table->date('payment_date')->nullable()->after('amount');
            $table->string('reference_number')->nullable()->after('payment_method');
            $table->string('idempotency_key', 100)->nullable()->after('reference_number');
            $table->unique(['company_id', 'idempotency_key'], 'po_payment_company_idempotency_unique');
            $table->index(['company_id', 'payment_date'], 'po_payment_company_date_idx');
        });

        DB::table('purchase_order_payments')->orderBy('id')->each(function ($payment) {
            $order = DB::table('purchase_orders')->where('id', $payment->purchase_order_id)->first();
            if ($order) {
                DB::table('purchase_order_payments')->where('id', $payment->id)->update([
                    'company_id' => $order->company_id,
                    'payment_date' => substr((string) $payment->paid_at, 0, 10),
                ]);
            }
        });

        DB::table('purchase_orders')->whereIn('status', ['paid', 'partially_paid'])->update(['status' => 'ordered']);

        Schema::create('purchase_order_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->text('reason')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'purchase_order_id', 'created_at'], 'po_change_company_order_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_changes');
        Schema::table('purchase_order_payments', function (Blueprint $table) {
            $table->dropUnique('po_payment_company_idempotency_unique');
            $table->dropIndex('po_payment_company_date_idx');
            $table->dropConstrainedForeignId('company_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['payment_date', 'reference_number', 'idempotency_key']);
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['unit', 'received_quantity']);
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex('po_company_status_ordered_idx');
            $table->dropIndex('po_company_due_idx');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['currency', 'expected_at', 'cancelled_at']);
        });
    }
};
