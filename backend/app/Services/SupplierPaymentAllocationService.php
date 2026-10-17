<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\PurchaseOrderPayment;
use App\Models\SupplierInvoicePaymentAllocation;
use App\Models\SupplierMatchEvent;
use App\Support\CompanyClock;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
    ): SupplierInvoicePaymentAllocation {
        return DB::transaction(function () use ($payment, $expense, $amount, $reason, $idempotencyKey): SupplierInvoicePaymentAllocation {
            DB::table('companies')->where('id', Auth::user()->company_id)->lockForUpdate()->first();
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $payment = PurchaseOrderPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $openingAdvance = $payment->onboarding_session_id && ! $payment->purchase_order_id
                && (int) $payment->supplier_id > 0 && (int) $payment->supplier_id === (int) $expense->supplier_id;
            if ((int) $payment->company_id !== (int) $expense->company_id
                || (! $openingAdvance && (int) $payment->purchase_order_id !== (int) $expense->purchase_order_id)
                || $payment->status !== 'completed'
                || ! in_array($expense->document_type, ['purchase_invoice', 'opening_balance'], true)
                || $expense->status !== 'posted') {
                throw ValidationException::withMessages(['expense_id' => ['Choose a posted supplier invoice from the same Purchase Order. Draft bills cannot consume advances.']]);
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

            if ($expense->invoice_date->toDateString() > CompanyClock::today()->toDateString() || $payment->payment_date->toDateString() > CompanyClock::today()->toDateString()) {
                throw ValidationException::withMessages(['allocation' => 'Allocate only effective bills and payments.']);
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
                $amount,
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
        if ($expense->invoice_date->toDateString() > CompanyClock::today()->toDateString() || $expense->status !== 'posted' || ! $expense->approved_at || ! $expense->purchase_order_id || $expense->document_type !== 'purchase_invoice') {
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
                    'Allocated from an existing Purchase Order advance after the supplier invoice was reviewed and posted.',
                    $key,
                );
            }
        }
    }

    public function reverseAllocation(int $requestId, string $date, string $reason): Expense
    {
        $date = CarbonImmutable::parse($date)->toDateString();

        return DB::transaction(function () use ($requestId, $date, $reason) {
            $company = Auth::user()->company_id;
            DB::table('companies')->where('id', $company)->lockForUpdate()->first();
            $request = DB::table('supplier_payment_allocation_requests')->where('company_id', $company)->where('id', $requestId)->first();
            abort_unless($request, 404);
            $expense = Expense::query()->lockForUpdate()->findOrFail($request->expense_id);
            $payment = PurchaseOrderPayment::query()->lockForUpdate()->findOrFail($request->purchase_order_payment_id);
            $allocation = SupplierInvoicePaymentAllocation::query()->lockForUpdate()->findOrFail($request->supplier_invoice_payment_allocation_id);
            abort_unless((int) $allocation->expense_id === (int) $expense->id && (int) $allocation->purchase_order_payment_id === (int) $payment->id, 422, 'Allocation source links differ; investigate before correcting.');
            $reason = trim($reason);
            if ($request->status === 'reversed') {
                abort_unless(($request->reversal_date ?? substr($request->reversed_at, 0, 10)) === $date && $request->reversal_reason === $reason, 409, 'This allocation already has a different reversal.');

                return $expense->fresh();
            }
            if ($date < substr($request->allocated_at, 0, 10) || $date > CompanyClock::today()->toDateString()) {
                throw ValidationException::withMessages(['reversal_date' => 'Use an effective date from the original allocation through today.']);
            }
            if (Money::compare($allocation->amount, $request->amount) < 0) {
                throw ValidationException::withMessages(['allocation' => 'Allocation history differs from its active total. Investigate before correcting.']);
            }
            if (! JournalEntry::where('source_module', 'supplier_accounting')->where('source_key', 'supplier-allocation:'.$request->id)->where('status', 'posted')->exists()) {
                throw ValidationException::withMessages(['allocation' => 'The original posted allocation journal is missing; investigate its integrity before correction.']);
            }
            app(AccountingService::class)->reverseSource('supplier_accounting', 'supplier-allocation:'.$request->id, $date, $reason);
            $allocation->update(['amount' => Money::subtract($allocation->amount, $request->amount), 'allocated_by' => Auth::id(), 'allocated_at' => now(), 'reason' => 'Released allocation: '.$reason]);
            DB::table('supplier_payment_allocation_requests')->where('company_id', $company)->where('id', $requestId)->update(['status' => 'reversed', 'reversal_date' => $date, 'reversed_at' => now(), 'reversed_by' => Auth::id(), 'reversal_reason' => $reason, 'updated_at' => now()]);
            $payment->update(['expense_id' => null]);
            SupplierMatchEvent::create(['company_id' => $company, 'expense_id' => $expense->id, 'action' => 'supplier_advance_unallocated', 'old_values' => ['allocation_request_id' => $requestId, 'amount' => $request->amount],
                'new_values' => ['remaining_allocated' => $allocation->amount, 'reversal_date' => $date], 'reason' => $reason, 'user_id' => Auth::id(), 'created_at' => now()]);
            ActivityLog::create(['company_id' => $company, 'user_id' => Auth::id(), 'action' => 'supplier.advance.unallocated', 'entity' => 'Expense', 'entity_id' => $expense->id, 'description' => 'Supplier advance allocation reversed', 'new_value' => ['request_id' => $requestId, 'date' => $date, 'reason' => $reason]]);

            return $expense->fresh();
        });
    }

    public function remaining(Expense $expense): string
    {
        $direct = $expense->payments()->where('status', 'completed')->sum('amount');
        $allocated = $expense->purchaseOrderPaymentAllocations()->sum('amount');
        $credits = $expense->supplierCredits()->sum('gross_amount');

        return Money::maximum('0.00', Money::subtract($expense->gross_amount, Money::add($direct, $allocated, $credits)));
    }
}
