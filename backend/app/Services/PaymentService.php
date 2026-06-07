<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function processPayment(Invoice $invoice, array $data): PaymentTransaction
    {
        if ((float) $invoice->remaining_balance <= 0) {
            throw ValidationException::withMessages([
                'invoice_id' => ['This invoice has already been fully paid.'],
            ]);
        }

        $amount = isset($data['amount']) ? round((float) $data['amount'], 2) : round((float) $invoice->remaining_balance, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Payment amount must be greater than zero.'],
            ]);
        }

        if ($amount > (float) $invoice->remaining_balance) {
            throw ValidationException::withMessages([
                'amount' => ['Payment amount cannot exceed the remaining balance of €'.number_format($invoice->remaining_balance, 2).'.'],
            ]);
        }

        return DB::transaction(function () use ($invoice, $data, $amount) {
            $transaction = PaymentTransaction::create([
                'company_id'      => Auth::user()->company_id,
                'invoice_id'      => $invoice->id,
                'amount'          => $amount,
                'status'          => 'completed',
                'payment_method'  => $data['payment_method'] ?? 'cash',
                'transaction_ref' => 'TXN-'.strtoupper(Str::random(12)),
                'note'            => $data['note'] ?? null,
                'paid_at'         => now(),
            ]);

            $newTotalPaid = round((float) $invoice->total_paid + $amount, 2);
            $remaining    = round((float) $invoice->total_amount - $newTotalPaid, 2);

            $newStatus = match (true) {
                $remaining <= 0     => 'paid',
                $newTotalPaid > 0   => 'partially_paid',
                default             => 'unpaid',
            };

            $invoice->update([
                'total_paid' => $newTotalPaid,
                'status'     => $newStatus,
                'paid_at'    => $newStatus === 'paid' ? now() : $invoice->paid_at,
            ]);

            return $transaction->load('invoice');
        });
    }

    public function listForCompany(): \Illuminate\Database\Eloquent\Collection
    {
        return PaymentTransaction::with('invoice')
            ->latest()
            ->limit(50)
            ->get();
    }
}
