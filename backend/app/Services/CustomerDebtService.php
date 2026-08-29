<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerDebtService
{
    public function __construct(private readonly FinancialAccountService $accounts) {}

    private const INCREASE_TYPES = ['debt_added', 'positive_adjustment', 'opening_balance'];

    public function addDebt(Customer $customer, array $data, string $type = 'debt_added'): CustomerDebtTransaction
    {
        if (! in_array($type, self::INCREASE_TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['Invalid debt increase type.']]);
        }

        return $this->record($customer, $data, $type, true);
    }

    public function recordPayment(Customer $customer, array $data): CustomerDebtTransaction
    {
        return DB::transaction(function () use ($customer, $data) {
            $transaction = $this->record($customer, $data, 'payment', false);
            if (! empty($data['financial_account_id']) && ! $transaction->financial_account_transaction_id) {
                $ledger = $this->accounts->post((int) $data['financial_account_id'], [
                    'type' => 'inflow', 'amount' => (float) $transaction->amount,
                    'transaction_date' => $transaction->transaction_date->toDateString(),
                    'source_type' => 'customer_debt_payment', 'source_id' => $transaction->id,
                    'counterparty' => $customer->name, 'reference_number' => $transaction->reference_number,
                    'description' => $transaction->note ?: 'Customer debt payment',
                    'idempotency_key' => ($data['idempotency_key'] ?? (string) \Illuminate\Support\Str::uuid()).'-ledger',
                ]);
                $transaction->update([
                    'financial_account_id' => $data['financial_account_id'],
                    'financial_account_transaction_id' => $ledger->id,
                ]);
            }
            return $transaction->fresh();
        });
    }

    public function reverse(CustomerDebtTransaction $transaction, string $reason): CustomerDebtTransaction
    {
        if ($transaction->reversed_transaction_id || $transaction->source === 'reversal') {
            throw ValidationException::withMessages(['transaction' => ['A correction entry cannot be corrected again.']]);
        }

        if (CustomerDebtTransaction::where('reversed_transaction_id', $transaction->id)->exists()) {
            throw ValidationException::withMessages(['transaction' => ['This transaction has already been corrected.']]);
        }

        $increase = ! in_array($transaction->type, self::INCREASE_TYPES, true);
        $type = $increase ? 'positive_adjustment' : 'cancellation';

        $reversal = $this->record($transaction->customer, [
            'amount' => $transaction->amount,
            'transaction_date' => now()->toDateString(),
            'note' => $reason,
            'source' => 'reversal',
            'reversed_transaction_id' => $transaction->id,
            'metadata' => ['reverses_transaction_id' => $transaction->id],
        ], $type, $increase, true);
        if ($transaction->financial_account_transaction_id) {
            $ledger = \App\Models\FinancialAccountTransaction::query()->find($transaction->financial_account_transaction_id);
            if ($ledger && $ledger->status === 'posted') {
                $this->accounts->reverse($ledger, 'Customer debt transaction reversal: '.$reason);
            }
        }

        return $reversal;
    }

    public function correct(CustomerDebtTransaction $transaction, array $data): CustomerDebtTransaction
    {
        return DB::transaction(function () use ($transaction, $data) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($transaction->customer_id);
            $lockedTransaction = CustomerDebtTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $before = $lockedTransaction->only([
                'amount',
                'transaction_date',
                'due_date',
                'payment_method',
                'reference_number',
                'note',
            ]);
            $metadata = $lockedTransaction->metadata ?? [];
            $history = $metadata['correction_history'] ?? [];
            $history[] = [
                'corrected_at' => now()->toIso8601String(),
                'corrected_by' => Auth::id(),
                'reason' => $data['reason'],
                'before' => $before,
            ];
            $metadata['correction_history'] = $history;

            $lockedTransaction->fill([
                'amount' => round((float) $data['amount'], 2),
                'transaction_date' => $data['transaction_date'],
                'due_date' => $data['due_date'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'note' => $data['note'] ?? null,
                'metadata' => $metadata,
            ])->save();

            $balance = 0.0;
            $transactions = CustomerDebtTransaction::query()
                ->where('customer_id', $customer->id)
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($transactions as $ledgerTransaction) {
                $amount = round((float) $ledgerTransaction->amount, 2);
                $beforeBalance = round($balance, 2);
                $increase = in_array($ledgerTransaction->type, self::INCREASE_TYPES, true);
                $balance = round($beforeBalance + ($increase ? $amount : -$amount), 2);

                $ledgerTransaction->forceFill([
                    'balance_before' => $beforeBalance,
                    'balance_after' => $balance,
                ])->saveQuietly();
            }

            $customer->forceFill(['current_debt' => $balance])->saveQuietly();

            return $lockedTransaction->fresh()->load('user:id,name')->loadCount('reversals');
        });
    }

    private function record(Customer $customer, array $data, string $type, bool $increase, bool $allowNegativeBalance = false): CustomerDebtTransaction
    {
        return DB::transaction(function () use ($customer, $data, $type, $increase, $allowNegativeBalance) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
            }

            if (! empty($data['idempotency_key'])) {
                $existing = CustomerDebtTransaction::where('company_id', $locked->company_id)
                    ->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $existing;
                }
            }

            $before = round((float) $locked->current_debt, 2);
            if (! $increase && ! $allowNegativeBalance && $amount > $before) {
                throw ValidationException::withMessages(['amount' => ['Payment cannot exceed the current customer debt.']]);
            }

            $after = round($before + ($increase ? $amount : -$amount), 2);
            $locked->update(['current_debt' => $after]);

            return CustomerDebtTransaction::create([
                'company_id' => $locked->company_id,
                'customer_id' => $locked->id,
                'user_id' => Auth::id(),
                'daily_sale_id' => $data['daily_sale_id'] ?? null,
                'reversed_transaction_id' => $data['reversed_transaction_id'] ?? null,
                'type' => $type,
                'source' => $data['source'] ?? 'manual',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'note' => $data['note'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);
        });
    }
}
