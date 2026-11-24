<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\FinancialAccount;
use App\Models\PaymentTransaction;
use App\Services\Kosovo\CashPaymentPolicy;
use App\Support\CompanyCurrency;
use App\Support\CompanyClock;
use App\Support\InvoiceExchange;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly FinancialAccountService $accounts,
        private readonly OperationalAccountingService $operationalAccounting,
    ) {}

    public function processPayment(Invoice $invoice, array $data): PaymentTransaction
    {
        $companyId = $this->requireCompanyId();
        abort_unless((int) $invoice->company_id === $companyId, 404);

        return DB::transaction(function () use ($invoice, $data, $companyId) {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->daily_sale_id && $invoice->dailySale?->outboundDispatch) throw ValidationException::withMessages(['order'=>['Record this payment through the linked order/customer ledger to avoid receiving it twice.']]);
            if ($invoice->document_type === 'credit_note' || in_array($invoice->status, ['draft', 'void', 'credited'], true)) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['Payments can be recorded only against an issued invoice that has not been voided or credited.'],
                ]);
            }

            $date = isset($data['payment_date']) ? CarbonImmutable::parse($data['payment_date'])->toDateString() : CompanyClock::today()->toDateString();
            $base = CompanyCurrency::forCompanyId($invoice->company_id);
            $currency = $invoice->currency ?: $base;
            $foreign = $currency !== $base;
            $rate = $foreign ? InvoiceExchange::snapshot($data, $date) : null;
            if (! empty($data['idempotency_key'])) {
                $existing = PaymentTransaction::query()
                    ->where('company_id', $invoice->company_id)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();
                if ($existing) {
                    if ((int) $existing->invoice_id !== (int) $invoice->id) {
                        throw ValidationException::withMessages(['idempotency_key' => ['This payment key is already used for another invoice.']]);
                    }
                    $mismatch = $existing->status !== 'completed'
                        || (isset($data['amount']) && Money::compare($existing->amount, $data['amount']) !== 0)
                        || $existing->payment_method !== ($data['payment_method'] ?? 'cash')
                        || $existing->payment_date?->toDateString() !== $date
                        || ($existing->reference_number ?? '') !== ($data['reference_number'] ?? '')
                        || ($existing->note ?? '') !== ($data['note'] ?? '')
                        || (int) ($existing->financial_account_id ?? 0) !== (int) ($data['financial_account_id'] ?? 0)
                        || ($foreign && array_intersect_key($existing->exchange_snapshot ?? [], $rate) !== $rate);
                    if ($mismatch) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['This payment key was already used with different payment details.'],
                        ]);
                    }

                    return $existing->load('invoice');
                }
            }

            $account = ! empty($data['financial_account_id']) ? FinancialAccount::query()->findOrFail($data['financial_account_id']) : null;
            if ($account && $account->currency !== $base) {
                throw ValidationException::withMessages(['financial_account_id' => ['Choose a financial account in the invoice currency.']]);
            }
            if ($foreign && ($base !== 'EUR' || ! isset($invoice->exchange_snapshot['base_total']) || $account?->type !== 'bank'
                || ! in_array($data['payment_method'] ?? null, ['bank_transfer', 'card'], true)
                || $date < $invoice->invoice_date->toDateString() || $date > CompanyClock::today()->toDateString())) {
                throw ValidationException::withMessages(['currency' => ['Use an evidenced historical invoice and an electronic settlement into a EUR bank account. The settlement date must fall between issuance and today.']]);
            }

            $invoiceTotal = Money::compare($invoice->grand_total, $invoice->total_amount) >= 0
                ? Money::normalize($invoice->grand_total)
                : Money::normalize($invoice->total_amount);
            if (($invoice->seller_snapshot['country_code'] ?? null) === 'XK' && $base === 'EUR') {
                app(CashPaymentPolicy::class)->assertAllowed(
                    $date, $invoice->exchange_snapshot['base_total'] ?? $invoiceTotal, $invoice->buyer_snapshot['customer_type'] ?? null, $data['payment_method'] ?? 'cash', $account, $invoice,
                );
            }
            $remainingBefore = Money::maximum('0.00', Money::subtract($invoiceTotal, $invoice->total_paid));
            if (Money::compare($remainingBefore, '0.00') <= 0) {
                throw ValidationException::withMessages(['invoice_id' => ['This invoice has already been fully paid.']]);
            }
            $amount = isset($data['amount']) ? Money::normalize($data['amount']) : $remainingBefore;
            if (Money::compare($amount, '0.00') <= 0 || Money::compare($amount, $remainingBefore) > 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Payment must be greater than zero and cannot exceed the remaining balance of '.number_format($invoice->remaining_balance, 2).' '.$currency.'.'],
                ]);
            }
            $received = $amount;
            if ($foreign) {
                $received = Money::multiply($amount, $rate['rate']);
                $oldCarrying = Money::multiply($remainingBefore, $invoice->exchange_snapshot['rate']);
                $newCarrying = Money::multiply(Money::subtract($remainingBefore, $amount), $invoice->exchange_snapshot['rate']);
                $carrying = Money::subtract($oldCarrying, $newCarrying);
                if (Money::compare($received, 0) <= 0 || Money::compare($carrying, 0) <= 0) {
                    throw ValidationException::withMessages(['amount' => ['Group instalments whose converted value rounds to zero before recording the settlement.']]);
                }
                $rate = [...$rate, 'carrying_base' => $carrying, 'received_base' => $received];
            }

            $transaction = PaymentTransaction::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'user_id' => Auth::id(),
                'amount' => $amount,
                'exchange_snapshot' => $rate,
                'status' => 'completed',
                'payment_method' => $data['payment_method'] ?? 'cash',
                'transaction_ref' => 'TXN-'.strtoupper(Str::random(12)),
                'reference_number' => $data['reference_number'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? (string) Str::uuid(),
                'note' => $data['note'] ?? null,
                'payment_date' => $date,
                'paid_at' => now(),
                'financial_account_id' => $data['financial_account_id'] ?? null,
            ]);

            if (! empty($data['financial_account_id'])) {
                $ledger = $this->accounts->post((int) $data['financial_account_id'], [
                    'type' => 'inflow', 'amount' => $received,
                    'transaction_date' => $date,
                    'source_type' => 'invoice_payment', 'source_id' => $transaction->id,
                    'counterparty' => $invoice->buyer_name ?? null,
                    'reference_number' => $data['reference_number'] ?? $transaction->transaction_ref,
                    'description' => 'Customer invoice payment '.$invoice->invoice_number,
                    'idempotency_key' => ($data['idempotency_key'] ?? $transaction->idempotency_key).'-ledger',
                ]);
                $transaction->update(['financial_account_transaction_id' => $ledger->id]);
            }

            $newTotalPaid = Money::add($invoice->total_paid, $amount);
            $remaining = Money::subtract($invoiceTotal, $newTotalPaid);
            $paymentStatus = Money::compare($remaining, '0.00') <= 0 ? 'paid' : 'partially_paid';
            $invoice->update([
                'total_paid' => $newTotalPaid,
                'status' => 'issued',
                'payment_status' => $paymentStatus,
                'payment_method' => $data['payment_method'] ?? $invoice->payment_method,
                'paid_at' => $paymentStatus === 'paid' ? now() : null,
                'updated_by' => Auth::id(),
            ]);

            $this->operationalAccounting->postInvoicePayment($transaction->fresh('invoice'));

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
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
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
            if ($payment->exchange_snapshot && PaymentTransaction::query()->where('invoice_id', $invoice->id)
                ->where('status', 'completed')->where('id', '>', $payment->id)->exists()) {
                throw ValidationException::withMessages(['payment' => ['Reverse later active settlements first to preserve the historical exchange calculation.']]);
            }

            $payment->update([
                'status' => 'reversed',
                'reversal_date' => \App\Support\CompanyClock::today()->toDateString(), 'reversed_at' => now(),
                'reversed_by' => Auth::id(),
                'reversal_reason' => trim($reason),
            ]);
            if ($payment->financial_account_transaction_id) {
                $ledger = \App\Models\FinancialAccountTransaction::query()->find($payment->financial_account_transaction_id);
                if ($ledger && $ledger->status === 'posted') {
                    $this->accounts->reverse($ledger, 'Invoice payment reversal: '.trim($reason));
                }
            }
            $this->operationalAccounting->reverseSource('sales', 'invoice-payment:'.$payment->id, 'Invoice payment reversal: '.trim($reason));

            $totalPaid = Money::normalize(PaymentTransaction::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', 'completed')
                ->whereNull('reversed_at')
                ->sum('amount'));
            $invoiceTotal = Money::compare($invoice->grand_total, $invoice->total_amount) >= 0
                ? Money::normalize($invoice->grand_total)
                : Money::normalize($invoice->total_amount);
            $remaining = Money::subtract($invoiceTotal, $totalPaid);
            $paymentStatus = Money::compare($totalPaid, '0.00') <= 0
                ? 'unpaid'
                : (Money::compare($remaining, '0.00') <= 0 ? 'paid' : 'partially_paid');

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
