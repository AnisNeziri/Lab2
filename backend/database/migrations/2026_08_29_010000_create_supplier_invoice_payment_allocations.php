<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_invoice_payment_allocations')) {
            Schema::create('supplier_invoice_payment_allocations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_order_payment_id');
                $table->foreign('purchase_order_payment_id', 'sipa_payment_fk')->references('id')->on('purchase_order_payments')->cascadeOnDelete();
                $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
                $table->decimal('amount', 15, 2);
                $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('allocated_at');
                $table->string('reason', 1000)->nullable();
                $table->timestamps();
                $table->unique(['purchase_order_payment_id', 'expense_id'], 'sipa_payment_expense_unique');
                $table->index(['company_id', 'expense_id'], 'sipa_company_expense_idx');
            });
        }

        // Preserve legacy allocations without allowing one advance to overpay
        // a lower supplier invoice. Any excess remains an unallocated PO advance.
        $payments = DB::table('purchase_order_payments')->whereNotNull('expense_id')->orderBy('id')->get();
        foreach ($payments as $payment) {
            $expense = DB::table('expenses')->where('id', $payment->expense_id)->first();
            if (! $expense
                || (int) $expense->company_id !== (int) $payment->company_id
                || (int) $expense->purchase_order_id !== (int) $payment->purchase_order_id
                || $expense->document_type !== 'purchase_invoice'
                || strtoupper((string) $expense->currency) !== strtoupper((string) $payment->currency)
                || $expense->status === 'reversed'
                || $expense->deleted_at !== null) {
                DB::table('purchase_order_payments')->where('id', $payment->id)->update(['expense_id' => null]);
                continue;
            }
            if (DB::table('supplier_invoice_payment_allocations')
                ->where('purchase_order_payment_id', $payment->id)
                ->where('expense_id', $expense->id)
                ->exists()) {
                $paymentAllocated = (float) DB::table('supplier_invoice_payment_allocations')
                    ->where('purchase_order_payment_id', $payment->id)->sum('amount');
                if (abs($paymentAllocated - (float) $payment->amount) > .001) {
                    DB::table('purchase_order_payments')->where('id', $payment->id)->update(['expense_id' => null]);
                }
                continue;
            }
            $direct = (float) DB::table('expense_payments')->where('expense_id', $expense->id)->where('status', 'completed')->sum('amount');
            $credits = (float) DB::table('expenses')->where('original_expense_id', $expense->id)->where('document_type', 'credit_note')->where('status', 'posted')->whereNull('deleted_at')->sum('gross_amount');
            $allocated = (float) DB::table('supplier_invoice_payment_allocations')->where('expense_id', $expense->id)->sum('amount');
            $amount = round(min((float) $payment->amount, max(0, (float) $expense->gross_amount - $direct - $credits - $allocated)), 2);
            if ($amount <= 0) {
                DB::table('purchase_order_payments')->where('id', $payment->id)->update(['expense_id' => null]);
                continue;
            }
            DB::table('supplier_invoice_payment_allocations')->insert([
                'company_id' => $payment->company_id,
                'purchase_order_payment_id' => $payment->id,
                'expense_id' => $expense->id,
                'amount' => $amount,
                'allocated_by' => $payment->user_id,
                'allocated_at' => $payment->paid_at ?? now(),
                'reason' => 'Migrated from the previous supplier-invoice payment link.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (abs($amount - (float) $payment->amount) > .001) {
                DB::table('purchase_order_payments')->where('id', $payment->id)->update(['expense_id' => null]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_payment_allocations');
    }
};
