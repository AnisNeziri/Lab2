<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\PurchaseOrderPayment;
use App\Models\SupplierInvoicePaymentAllocation;
use App\Models\SupplierMatchEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierPaymentAllocationService
{
    public function allocate(PurchaseOrderPayment $payment, Expense $expense, float $amount, ?string $reason = null): SupplierInvoicePaymentAllocation
    {
        return DB::transaction(function () use ($payment, $expense, $amount, $reason): SupplierInvoicePaymentAllocation {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $payment = PurchaseOrderPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ((int) $payment->company_id !== (int) $expense->company_id
                || (int) $payment->purchase_order_id !== (int) $expense->purchase_order_id
                || $expense->document_type !== 'purchase_invoice'
                || $expense->status === 'reversed') {
                throw ValidationException::withMessages(['expense_id' => ['Choose an active supplier invoice from the same Purchase Order.']]);
            }
            if (strtoupper((string) $payment->currency) !== strtoupper((string) $expense->currency)) {
                throw ValidationException::withMessages(['expense_id' => ['The Purchase Order payment and supplier invoice must use the same currency.']]);
            }

            $amount = round($amount, 2);
            $existing = SupplierInvoicePaymentAllocation::query()
                ->where('purchase_order_payment_id', $payment->id)->where('expense_id', $expense->id)
                ->lockForUpdate()->first();
            $allocatedFromPayment = (float) SupplierInvoicePaymentAllocation::query()
                ->where('purchase_order_payment_id', $payment->id)->lockForUpdate()->sum('amount');
            $paymentAvailable = round((float) $payment->amount - $allocatedFromPayment, 2);
            $invoiceRemaining = $this->remaining($expense);
            if ($amount <= 0 || $amount > $paymentAvailable + .001) {
                throw ValidationException::withMessages(['amount' => ['Allocation exceeds the unallocated amount of this Purchase Order payment.']]);
            }
            if ($amount > $invoiceRemaining + .001) {
                throw ValidationException::withMessages(['amount' => ['Allocation exceeds the remaining supplier invoice payable.']]);
            }

            if ($existing) {
                $existing->update([
                    'amount' => round((float) $existing->amount + $amount, 2),
                    'allocated_by' => Auth::id(), 'allocated_at' => now(),
                    'reason' => $reason ?: $existing->reason,
                ]);
                $allocation = $existing;
            } else {
                $allocation = SupplierInvoicePaymentAllocation::create([
                    'company_id' => $payment->company_id,
                    'purchase_order_payment_id' => $payment->id, 'expense_id' => $expense->id,
                    'amount' => $amount, 'allocated_by' => Auth::id(), 'allocated_at' => now(),
                    'reason' => $reason,
                ]);
            }

            $allAllocations = $payment->allocations()->get();
            $payment->update([
                'expense_id' => $allAllocations->count() === 1
                    && abs((float) $allAllocations->first()->amount - (float) $payment->amount) < .001
                    ? $expense->id : null,
            ]);
            SupplierMatchEvent::create([
                'company_id' => $expense->company_id, 'expense_id' => $expense->id,
                'action' => 'purchase_order_payment_allocated', 'old_values' => null,
                'new_values' => ['purchase_order_payment_id' => $payment->id, 'amount' => $amount],
                'reason' => $reason, 'user_id' => Auth::id(), 'created_at' => now(),
            ]);

            return $allocation->fresh(['payment', 'expense']);
        });
    }

    public function autoAllocateForInvoice(Expense $expense): void
    {
        if (! $expense->purchase_order_id || $expense->document_type !== 'purchase_invoice') {
            return;
        }
        foreach (PurchaseOrderPayment::query()->where('purchase_order_id', $expense->purchase_order_id)->oldest('id')->get() as $payment) {
            $remaining = $this->remaining($expense);
            if ($remaining <= .001) {
                break;
            }
            $available = round((float) $payment->amount - (float) $payment->allocations()->sum('amount'), 2);
            if ($available > .001) {
                $this->allocate($payment, $expense, min($available, $remaining), 'Allocated from an existing Purchase Order advance when the supplier invoice was recorded.');
            }
        }
    }

    public function remaining(Expense $expense): float
    {
        $direct = (float) $expense->payments()->where('status', 'completed')->sum('amount');
        $allocated = (float) $expense->purchaseOrderPaymentAllocations()->sum('amount');
        $credits = (float) $expense->supplierCredits()->sum('gross_amount');

        return max(0, round((float) $expense->gross_amount - $direct - $allocated - $credits, 2));
    }
}
