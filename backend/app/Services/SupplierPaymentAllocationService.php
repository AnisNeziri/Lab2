<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\PurchaseOrderPayment;
use App\Models\SupplierInvoicePaymentAllocation;
use App\Models\SupplierMatchEvent;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierPaymentAllocationService
{
    public function __construct(private readonly OperationalAccountingService $operationalAccounting) {}

    public function allocate(
        PurchaseOrderPayment $payment,
        Expense $expense,
        mixed $amount,
        ?string $reason,
        string $idempotencyKey,
    ): SupplierInvoicePaymentAllocation
    {
        return DB::transaction(function () use ($payment, $expense, $amount, $reason, $idempotencyKey): SupplierInvoicePaymentAllocation {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $payment = PurchaseOrderPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ((int) $payment->company_id !== (int) $expense->company_id
                || (int) $payment->purchase_order_id !== (int) $expense->purchase_order_id
                || $payment->status !== 'completed'
                || $expense->document_type !== 'purchase_invoice'
                || $expense->status === 'reversed') {
                throw ValidationException::withMessages(['expense_id' => ['Choose an active supplier invoice from the same Purchase Order.']]);
            }
            if (strtoupper((string) $payment->currency) !== strtoupper((string) $expense->currency)) {
                throw ValidationException::withMessages(['expense_id' => ['The Purchase Order payment and supplier invoice must use the same currency.']]);
            }

            $amount = Money::normalize($amount);
            $request = DB::table('supplier_payment_allocation_requests')
                ->where('company_id', $expense->company_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($request) {
                if ($request->status !== 'completed') {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This allocation request was reversed and cannot be reused.'],
                    ]);
                }
                $same = (int) $request->purchase_order_payment_id === (int) $payment->id
                    && (int) $request->expense_id === (int) $expense->id
                    && Money::compare($request->amount, $amount) === 0
                    && ($request->reason ?? '') === ($reason ?? '');
                if (! $same) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This key was already used with different supplier-payment allocation details.'],
                    ]);
                }

                return SupplierInvoicePaymentAllocation::query()
                    ->findOrFail($request->supplier_invoice_payment_allocation_id);
            }

            $existing = SupplierInvoicePaymentAllocation::query()
                ->where('purchase_order_payment_id', $payment->id)->where('expense_id', $expense->id)
                ->lockForUpdate()->first();
            $allocatedFromPayment = SupplierInvoicePaymentAllocation::query()
                ->where('purchase_order_payment_id', $payment->id)->lockForUpdate()->sum('amount');
            $paymentAvailable = Money::subtract($payment->amount, $allocatedFromPayment);
            $invoiceRemaining = $this->remaining($expense);
            if (Money::compare($amount, '0.00') <= 0 || Money::compare($amount, $paymentAvailable) > 0) {
                throw ValidationException::withMessages(['amount' => ['Allocation exceeds the unallocated amount of this Purchase Order payment.']]);
            }
            if (Money::compare($amount, $invoiceRemaining) > 0) {
                throw ValidationException::withMessages(['amount' => ['Allocation exceeds the remaining supplier invoice payable.']]);
            }

            if ($existing) {
                $existing->update([
                    'amount' => Money::add($existing->amount, $amount),
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
                    && Money::compare($allAllocations->first()->amount, $payment->amount) === 0
                    ? $expense->id : null,
            ]);
            $requestId = DB::table('supplier_payment_allocation_requests')->insertGetId([
                'company_id' => $expense->company_id,
                'supplier_invoice_payment_allocation_id' => $allocation->id,
                'purchase_order_payment_id' => $payment->id,
                'expense_id' => $expense->id,
                'amount' => $amount,
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
                'allocated_by' => Auth::id(),
                'allocated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->operationalAccounting->postSupplierAdvanceApplication(
                $allocation->fresh(['payment', 'expense']),
                'supplier-allocation:'.$requestId,
            );
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
        foreach (PurchaseOrderPayment::query()->where('purchase_order_id', $expense->purchase_order_id)->where('status', 'completed')->oldest('id')->get() as $payment) {
            $remaining = $this->remaining($expense);
            if (Money::compare($remaining, '0.00') <= 0) {
                break;
            }
            $allocated = $payment->allocations()->sum('amount');
            $available = Money::subtract($payment->amount, $allocated);
            if (Money::compare($available, '0.00') > 0) {
                $amount = Money::minimum($available, $remaining);
                $key = 'auto-'.$expense->id.'-'.$payment->id.'-'.sha1($allocated.'|'.$amount);
                $this->allocate(
                    $payment,
                    $expense,
                    $amount,
                    'Allocated from an existing Purchase Order advance when the supplier invoice was recorded.',
                    $key,
                );
            }
        }
    }

    public function remaining(Expense $expense): string
    {
        $direct = $expense->payments()->where('status', 'completed')->sum('amount');
        $allocated = $expense->purchaseOrderPaymentAllocations()->sum('amount');
        $credits = $expense->supplierCredits()->sum('gross_amount');

        return Money::maximum('0.00', Money::subtract($expense->gross_amount, Money::add($direct, $allocated, $credits)));
    }
}
