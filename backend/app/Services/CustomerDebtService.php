<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
                    'type' => 'inflow', 'amount' => $transaction->amount,
                    'transaction_date' => $transaction->transaction_date->toDateString(),
                    'source_type' => 'customer_debt_payment', 'source_id' => $transaction->id,
                    'counterparty' => $customer->name, 'reference_number' => $transaction->reference_number,
                    'description' => $transaction->note ?: 'Customer debt payment',
                    'idempotency_key' => ($data['idempotency_key'] ?? (string) Str::uuid()).'-ledger',
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
        return DB::transaction(function () use ($transaction, $reason) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($transaction->customer_id);
            $locked = CustomerDebtTransaction::query()
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            return $this->createReversal($locked, $reason);
        });
    }

    public function correct(CustomerDebtTransaction $transaction, array $data): CustomerDebtTransaction
    {
        return DB::transaction(function () use ($transaction, $data) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($transaction->customer_id);
            $locked = CustomerDebtTransaction::query()
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->findOrFail($transaction->id);
            $before = $locked->only([
                'amount', 'transaction_date', 'due_date', 'payment_method',
                'reference_number', 'note', 'metadata',
            ]);

            $this->createReversal($locked, $data['reason']);

            $replacement = [
                ...$data,
                'payment_method' => $data['payment_method'] ?? $locked->payment_method,
                'source' => 'correction',
                'idempotency_key' => 'debt-correction-'.$locked->id.'-'.Str::uuid(),
                'financial_account_id' => $locked->financial_account_id,
                'metadata' => [
                    'corrects_transaction_id' => $locked->id,
                    'correction_reason' => $data['reason'],
                    'previous_values' => $before,
                ],
            ];

            $corrected = $locked->type === 'payment'
                ? $this->recordPayment($customer, $replacement)
                : $this->record(
                    $customer,
                    $replacement,
                    $locked->type,
                    in_array($locked->type, self::INCREASE_TYPES, true),
                );

            return $corrected->load('user:id,name')->loadCount('reversals');
        });
    }

    private function record(Customer $customer, array $data, string $type, bool $increase): CustomerDebtTransaction
    {
        return DB::transaction(function () use ($customer, $data, $type, $increase) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $amount = Money::normalize($data['amount']);

            if (Money::compare($amount, '0.00') <= 0) {
                throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
            }

            if (! empty($data['idempotency_key'])) {
                $existing = CustomerDebtTransaction::where('company_id', $locked->company_id)
                    ->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    $same = (int) $existing->customer_id === (int) $locked->id
                        && $existing->type === $type
                        && Money::compare($existing->amount, $amount) === 0
                        && $existing->transaction_date?->toDateString() === (string) ($data['transaction_date'] ?? now()->toDateString())
                        && ($existing->due_date?->toDateString() ?? '') === ($data['due_date'] ?? '')
                        && ($existing->payment_method ?? '') === ($data['payment_method'] ?? '')
                        && ($existing->reference_number ?? '') === ($data['reference_number'] ?? '')
                        && ($existing->note ?? '') === ($data['note'] ?? '')
                        && (int) ($existing->daily_sale_id ?? 0) === (int) ($data['daily_sale_id'] ?? 0)
                        && ($existing->source ?? 'manual') === ($data['source'] ?? 'manual')
                        && (int) ($existing->financial_account_id ?? 0) === (int) ($data['financial_account_id'] ?? 0);
                    if (! $same) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['This key was already used with different debt transaction details.'],
                        ]);
                    }

                    return $existing;
                }
            }

            $before = Money::normalize($locked->current_debt);
            $creditBefore = Money::normalize($locked->current_credit ?? '0.00');
            if ($increase) {
                $creditApplied = Money::minimum($amount, $creditBefore);
                $after = Money::add($before, Money::subtract($amount, $creditApplied));
                $creditAfter = Money::subtract($creditBefore, $creditApplied);
                $creditMetadata = Money::compare($creditApplied, '0.00') > 0
                    ? ['credit_applied' => $creditApplied] : [];
            } else {
                $debtApplied = Money::minimum($amount, $before);
                $advanceCreated = Money::subtract($amount, $debtApplied);
                $after = Money::subtract($before, $debtApplied);
                $creditAfter = Money::add($creditBefore, $advanceCreated);
                $creditMetadata = Money::compare($advanceCreated, '0.00') > 0
                    ? ['credit_created' => $advanceCreated] : [];
            }
            $locked->update(['current_debt' => $after, 'current_credit' => $creditAfter]);
            $metadata = array_filter([
                ...($data['metadata'] ?? []),
                ...$creditMetadata,
            ], fn ($value) => $value !== null);

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
                'credit_before' => $creditBefore,
                'credit_after' => $creditAfter,
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'note' => $data['note'] ?? null,
                'metadata' => $metadata ?: null,
            ]);
        });
    }

    private function createReversal(CustomerDebtTransaction $transaction, string $reason): CustomerDebtTransaction
    {
        if ($transaction->reversed_transaction_id || $transaction->source === 'reversal') {
            throw ValidationException::withMessages(['transaction' => ['A reversal entry cannot itself be reversed.']]);
        }
        if (CustomerDebtTransaction::query()->where('reversed_transaction_id', $transaction->id)->exists()) {
            throw ValidationException::withMessages(['transaction' => ['This transaction has already been reversed or corrected.']]);
        }

        $increase = ! in_array($transaction->type, self::INCREASE_TYPES, true);
        $reversal = $this->record($transaction->customer, [
            'amount' => $transaction->amount,
            'transaction_date' => now('Europe/Tirane')->toDateString(),
            'note' => $reason,
            'source' => 'reversal',
            'reversed_transaction_id' => $transaction->id,
            'idempotency_key' => 'debt-reversal-'.$transaction->id,
            'metadata' => ['reverses_transaction_id' => $transaction->id, 'reason' => $reason],
        ], $increase ? 'positive_adjustment' : 'cancellation', $increase);

        if ($transaction->financial_account_transaction_id) {
            $ledger = \App\Models\FinancialAccountTransaction::query()->find($transaction->financial_account_transaction_id);
            if ($ledger && $ledger->status === 'posted') {
                $this->accounts->reverse($ledger, 'Customer debt transaction reversal: '.$reason);
            }
        }

        return $reversal;
    }
}
