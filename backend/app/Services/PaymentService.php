<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function processPayment(Invoice $invoice, array $data): PaymentTransaction
    {
        if ($invoice->status === 'paid') {
            throw ValidationException::withMessages([
                'invoice_id' => ['This invoice has already been paid.'],
            ]);
        }

        $amount = $data['amount'] ?? $invoice->total_amount;

        $transaction = PaymentTransaction::create([
            'company_id' => Auth::user()->company_id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'status' => 'completed',
            'payment_method' => $data['payment_method'] ?? 'card',
            'transaction_ref' => 'TXN-' . strtoupper(Str::random(12)),
            'paid_at' => now(),
        ]);

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return $transaction->load('invoice');
    }

    public function listForCompany(): \Illuminate\Database\Eloquent\Collection
    {
        return PaymentTransaction::with('invoice')
            ->latest()
            ->limit(50)
            ->get();
    }
}
