<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private readonly FinancialAccountService $accounts) {}

    public function processPayment(Invoice $invoice, array $data): PaymentTransaction
    {
        $companyId = $this->requireCompanyId();
        abort_unless((int) $invoice->company_id === $companyId, 404);

        return DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->document_type === 'credit_note' || in_array($invoice->status, ['draft', 'void', 'credited'], true)) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['Payments can be recorded only against an issued invoice that has not been voided or credited.'],
                ]);
            }

            if (! empty($data['idempotency_key'])) {
                $existing = PaymentTransaction::query()
                    ->where('company_id', $invoice->company_id)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();
                if ($existing) {
                    if ((int) $existing->invoice_id !== (int) $invoice->id) {
                        throw ValidationException::withMessages(['idempotency_key' => ['This payment key is already used for another invoice.']]);
                    }
                    $mismatch = (isset($data['amount']) && abs((float) $existing->amount - round((float) $data['amount'], 2)) > 0.001)
                        || (isset($data['payment_method']) && $existing->payment_method !== $data['payment_method'])
                        || (isset($data['payment_date']) && $existing->payment_date?->toDateString() !== $data['payment_date'])
                        || (isset($data['reference_number']) && $existing->reference_number !== $data['reference_number'])
                        || (isset($data['note']) && $existing->note !== $data['note']);
                    if ($mismatch) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['This payment key was already used with different payment details.'],
                        ]);
                    }

                    return $existing->load('invoice');
                }
            }

            if ((float) $invoice->remaining_balance <= 0) {
                throw ValidationException::withMessages(['invoice_id' => ['This invoice has already been fully paid.']]);
            }
            $amount = isset($data['amount'])
                ? round((float) $data['amount'], 2)
                : round((float) $invoice->remaining_balance, 2);
            if ($amount <= 0 || $amount > (float) $invoice->remaining_balance) {
                throw ValidationException::withMessages([
                    'amount' => ['Payment must be greater than zero and cannot exceed the remaining balance of €'.number_format($invoice->remaining_balance, 2).'.'],
                ]);
            }

            $transaction = PaymentTransaction::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'user_id' => Auth::id(),
                'amount' => $amount,
                'status' => 'completed',
                'payment_method' => $data['payment_method'] ?? 'cash',
                'transaction_ref' => 'TXN-'.strtoupper(Str::random(12)),
                'reference_number' => $data['reference_number'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? (string) Str::uuid(),
                'note' => $data['note'] ?? null,
                'payment_date' => $data['payment_date'] ?? now('Europe/Belgrade')->toDateString(),
                'paid_at' => now(),
                'financial_account_id' => $data['financial_account_id'] ?? null,
            ]);

            if (! empty($data['financial_account_id'])) {
                $ledger = $this->accounts->post((int) $data['financial_account_id'], [
                    'type' => 'inflow', 'amount' => $amount,
                    'transaction_date' => $data['payment_date'] ?? now('Europe/Belgrade')->toDateString(),
                    'source_type' => 'invoice_payment', 'source_id' => $transaction->id,
                    'counterparty' => $invoice->buyer_name ?? null,
                    'reference_number' => $data['reference_number'] ?? $transaction->transaction_ref,
                    'description' => 'Customer invoice payment '.$invoice->invoice_number,
                    'idempotency_key' => ($data['idempotency_key'] ?? $transaction->idempotency_key).'-ledger',
                ]);
                $transaction->update(['financial_account_transaction_id' => $ledger->id]);
            }

            $newTotalPaid = round((float) $invoice->total_paid + $amount, 2);
            $remaining = round((float) $invoice->grand_total - $newTotalPaid, 2);
            $paymentStatus = $remaining <= 0 ? 'paid' : 'partially_paid';
            $invoice->update([
                'total_paid' => $newTotalPaid,
                'status' => 'issued',
                'payment_status' => $paymentStatus,
                'payment_method' => $data['payment_method'] ?? $invoice->payment_method,
                'paid_at' => $paymentStatus === 'paid' ? now() : null,
                'updated_by' => Auth::id(),
            ]);

            return $transaction->load('invoice');
        });
    }

    public function listForCompany(): Collection
    {
        $this->requireCompanyId();

        return PaymentTransaction::with(['invoice', 'user:id,name'])
            ->latest('payment_date')
            ->latest('id')
            ->limit(100)
            ->get();
    }

    public function reversePayment(PaymentTransaction $payment, string $reason): PaymentTransaction
    {
        $companyId = $this->requireCompanyId();
        abort_unless((int) $payment->company_id === $companyId, 404);

        return DB::transaction(function () use ($payment, $reason, $companyId) {
            $payment = PaymentTransaction::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless((int) $payment->company_id === $companyId, 404);

            if ($payment->status !== 'completed' || $payment->reversed_at) {
                throw ValidationException::withMessages([
                    'payment' => ['Only a completed payment that has not already been reversed can be reversed.'],
                ]);
            }

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            abort_unless((int) $invoice->company_id === $companyId, 404);
            if ($invoice->document_type !== 'invoice' || in_array($invoice->status, ['draft', 'void', 'credited'], true)) {
                throw ValidationException::withMessages([
                    'payment' => ['This payment cannot be reversed for the current invoice state.'],
                ]);
            }

            $payment->update([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => Auth::id(),
                'reversal_reason' => trim($reason),
            ]);
            if ($payment->financial_account_transaction_id) {
                $ledger = \App\Models\FinancialAccountTransaction::query()->find($payment->financial_account_transaction_id);
                if ($ledger && $ledger->status === 'posted') {
                    $this->accounts->reverse($ledger, 'Invoice payment reversal: '.trim($reason));
                }
            }

            $totalPaid = round((float) PaymentTransaction::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', 'completed')
                ->whereNull('reversed_at')
                ->sum('amount'), 2);
            $remaining = round((float) $invoice->grand_total - $totalPaid, 2);
            $paymentStatus = $totalPaid <= 0
                ? 'unpaid'
                : ($remaining <= 0 ? 'paid' : 'partially_paid');

            $invoice->update([
                'total_paid' => $totalPaid,
                'status' => 'issued',
                'payment_status' => $paymentStatus,
                'paid_at' => $paymentStatus === 'paid' ? ($invoice->paid_at ?? now()) : null,
                'updated_by' => Auth::id(),
            ]);

            return $payment->fresh()->load(['invoice', 'reversedBy:id,name']);
        });
    }

    private function requireCompanyId(): int
    {
        $companyId = Auth::user()?->company_id;
        abort_unless($companyId, 403, 'Select an explicit company context before using company invoice data.');

        return (int) $companyId;
    }
}
