<?php

namespace App\Services;

use App\Exceptions\CustomerCreditBlockedException;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerDebtService
{
    public function __construct(
        private readonly FinancialAccountService $accounts,
        private readonly CustomerCreditService $credit,
        private readonly ApprovalService $approvals,
        private readonly BusinessEventService $events,
    ) {}

    private const INCREASE_TYPES = ['debt_added', 'positive_adjustment', 'opening_balance'];

    public function addDebt(Customer $customer, array $data, string $type = 'debt_added'): CustomerDebtTransaction
    {
        if (! in_array($type, self::INCREASE_TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['Invalid debt increase type.']]);
        }

        return DB::transaction(function () use ($customer, $data, $type) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $amount = Money::normalize($data['amount']);
            if (Money::compare($amount, '0.00') <= 0) {
                throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
            }

            $data = $this->applyPaymentTerms($locked, $data);
            $intent = $this->creditIntent($locked, $data, $type);
            $data['metadata'] = [
                ...($data['metadata'] ?? []),
                'credit_intent_signature' => $intent['intent_signature'],
                'source_entity' => $intent['source_entity'],
                'source_reference' => $intent['source_reference'],
            ];
            if (is_array($data['source'] ?? null)) {
                unset($data['source']);
            }

            if ($existing = $this->existingDebt($locked, $data, $type, $intent['intent_signature'])) {
                return $existing;
            }

            $summary = $this->credit->exposure($locked);
            $projectedExposure = Money::add($summary['total_exposure'], $amount);
            $block = $this->creditBlock($locked, $summary, $projectedExposure);
            $approval = null;

            if ($block !== null) {
                $approval = ! empty($data['approval_request_id'])
                    ? $this->approvals->lockCustomerCreditOverride((int) $data['approval_request_id'], $locked)
                    : null;
                $signatureMatches = $approval && hash_equals(
                    (string) data_get($approval->context, 'intent_signature', ''),
                    $intent['intent_signature'],
                );

                if (! $approval || $approval->status !== 'approved' || ! $signatureMatches) {
                    throw new CustomerCreditBlockedException(
                        $this->creditBlockMessage($block, $approval, $signatureMatches),
                        $this->creditControlDetails(
                            $locked,
                            $summary,
                            $amount,
                            $projectedExposure,
                            $block,
                            $intent,
                            $approval,
                            $signatureMatches,
                        ),
                    );
                }
            } elseif (! empty($data['approval_request_id'])) {
                // If the risk condition disappeared after approval, consuming a
                // matching approval still prevents it being reused later.
                $candidate = $this->approvals->lockCustomerCreditOverride((int) $data['approval_request_id'], $locked);
                if ($candidate && $candidate->status === 'approved' && hash_equals(
                    (string) data_get($candidate->context, 'intent_signature', ''),
                    $intent['intent_signature'],
                )) {
                    $approval = $candidate;
                }
            }

            if ($approval) {
                $data['metadata']['credit_override_approval_request_id'] = $approval->id;
            }

            $transaction = $this->record($locked, $data, $type, true);
            if ($approval) {
                $this->approvals->consumeCustomerCreditOverride($approval, $transaction);
            }

            return $transaction;
        });
    }

    public function requestCreditOverride(Customer $customer, array $data, string $type = 'debt_added'): ApprovalRequest
    {
        if (! in_array($type, self::INCREASE_TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['Invalid debt increase type.']]);
        }

        return DB::transaction(function () use ($customer, $data, $type) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $amount = Money::normalize($data['amount']);
            $data = $this->applyPaymentTerms($locked, $data);
            $intent = $this->creditIntent($locked, $data, $type);
            $summary = $this->credit->exposure($locked);
            $projectedExposure = Money::add($summary['total_exposure'], $amount);
            $block = $this->creditBlock($locked, $summary, $projectedExposure);

            if ($block === null) {
                throw ValidationException::withMessages([
                    'credit_override' => ['This transaction is within the customer credit controls and does not require an override.'],
                ]);
            }

            return $this->approvals->requestCustomerCreditOverride(
                $locked,
                $amount,
                CompanyCurrency::forCompanyId((int) $locked->company_id),
                [
                    ...$intent,
                    'customer_name' => $locked->name,
                    'current_exposure' => Money::normalize($summary['total_exposure']),
                    'projected_exposure' => $projectedExposure,
                    'credit_limit' => $summary['credit_limit'] === null ? null : Money::normalize($summary['credit_limit']),
                    'credit_status' => $locked->credit_status,
                    'block_reason' => $block,
                    'override_reason' => $data['reason'],
                ],
            );
        });
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
            $this->events->record('customer.payment_received', $transaction, $transaction->reference_number, [
                'customer_id' => $customer->id, 'amount' => $transaction->amount,
                'transaction_date' => $transaction->transaction_date?->toDateString(),
            ], "customer-payment:{$transaction->id}:received");
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
                : (in_array($locked->type, self::INCREASE_TYPES, true)
                    ? $this->addDebt($customer, $replacement, $locked->type)
                    : $this->record($customer, $replacement, $locked->type, false));

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

            if ($increase) {
                $data = $this->applyPaymentTerms($locked, $data);
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

    private function creditIntent(Customer $customer, array $data, string $type): array
    {
        $nestedSource = is_array($data['source'] ?? null) ? $data['source'] : [];
        $transactionSource = is_string($data['source'] ?? null) ? $data['source'] : 'manual';
        $sourceEntity = trim((string) ($data['source_entity']
            ?? $nestedSource['entity_type']
            ?? ($transactionSource !== 'manual' ? $transactionSource : 'customer_debt_entry')));
        $sourceReference = trim((string) ($data['source_reference']
            ?? $nestedSource['reference']
            ?? $data['reference_number']
            ?? $data['idempotency_key']
            ?? ''));

        $intent = [
            'company_id' => (int) $customer->company_id,
            'customer_id' => (int) $customer->id,
            'proposed_amount' => Money::normalize($data['amount']),
            'transaction_type' => $type,
            'transaction_date' => CarbonImmutable::parse($data['transaction_date'] ?? now())->toDateString(),
            'due_date' => ! empty($data['due_date'])
                ? CarbonImmutable::parse($data['due_date'])->toDateString()
                : null,
            'reference_number' => filled($data['reference_number'] ?? null)
                ? trim((string) $data['reference_number'])
                : null,
            'idempotency_key' => (string) ($data['idempotency_key'] ?? ''),
            'source_entity' => $sourceEntity ?: 'customer_debt_entry',
            'source_reference' => $sourceReference,
            'transaction_source' => $transactionSource,
            'daily_sale_id' => isset($data['daily_sale_id']) ? (int) $data['daily_sale_id'] : null,
            'invoice_id' => isset($data['invoice_id']) ? (int) $data['invoice_id'] : null,
        ];
        $intent['intent_signature'] = hash('sha256', json_encode(
            $intent,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $intent;
    }

    private function applyPaymentTerms(Customer $customer, array $data): array
    {
        if (! empty($data['due_date']) || $customer->payment_terms_days === null) {
            return $data;
        }

        $transactionDate = CarbonImmutable::parse(
            $data['transaction_date'] ?? now('Europe/Tirane'),
            'Europe/Tirane',
        );
        $data['due_date'] = $transactionDate
            ->addDays((int) $customer->payment_terms_days)
            ->toDateString();

        return $data;
    }

    private function creditBlock(Customer $customer, array $summary, string $projectedExposure): ?string
    {
        if (strtolower((string) $customer->credit_status) === 'blocked') {
            return 'customer_blocked';
        }
        if ($summary['credit_limit'] !== null
            && Money::compare($projectedExposure, $summary['credit_limit']) > 0) {
            return 'over_limit';
        }

        return null;
    }

    private function creditBlockMessage(string $block, ?ApprovalRequest $approval, bool $signatureMatches): string
    {
        $base = $block === 'customer_blocked'
            ? 'This customer is blocked for credit transactions.'
            : 'This transaction exceeds the customer credit limit.';

        if (! $approval) {
            return $base.' An approved credit override is required.';
        }
        if (! $signatureMatches) {
            return $base.' The selected approval is for a different customer or transaction.';
        }

        return match ($approval->status) {
            'pending' => $base.' The credit override is still pending.',
            'rejected' => $base.' The credit override was rejected.',
            'consumed' => $base.' The credit override was already used.',
            default => $base.' An approved, unused credit override is required.',
        };
    }

    private function creditControlDetails(
        Customer $customer,
        array $summary,
        string $amount,
        string $projectedExposure,
        string $block,
        array $intent,
        ?ApprovalRequest $approval,
        bool $signatureMatches,
    ): array {
        return [
            'blocked' => true,
            'reason_code' => $block,
            'customer_id' => $customer->id,
            'credit_status' => $customer->credit_status,
            'credit_limit' => $summary['credit_limit'] === null ? null : Money::normalize($summary['credit_limit']),
            'current_exposure' => Money::normalize($summary['total_exposure']),
            'proposed_amount' => $amount,
            'projected_exposure' => $projectedExposure,
            'available_credit' => $summary['available_credit'],
            'override_allowed' => true,
            'approval_request_id' => $approval?->id,
            'approval_status' => $approval?->status,
            'approval_matches_transaction' => $approval ? $signatureMatches : null,
            'intent_signature' => $intent['intent_signature'],
            'source_entity' => $intent['source_entity'],
            'source_reference' => $intent['source_reference'],
        ];
    }

    private function existingDebt(Customer $customer, array $data, string $type, string $intentSignature): ?CustomerDebtTransaction
    {
        if (empty($data['idempotency_key'])) {
            return null;
        }

        $existing = CustomerDebtTransaction::query()
            ->where('company_id', $customer->company_id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if (! $existing) {
            return null;
        }

        $existingSignature = (string) data_get($existing->metadata, 'credit_intent_signature', '');
        $signedMatch = $existingSignature !== '' && hash_equals($existingSignature, $intentSignature);
        $legacyMatch = (int) $existing->customer_id === (int) $customer->id
            && $existing->type === $type
            && Money::compare($existing->amount, $data['amount']) === 0
            && $existing->transaction_date?->toDateString() === (string) ($data['transaction_date'] ?? now()->toDateString())
            && (! array_key_exists('due_date', $data)
                || ($existing->due_date?->toDateString() ?? '') === ($data['due_date'] ?? ''))
            && ($existing->payment_method ?? '') === ($data['payment_method'] ?? '')
            && ($existing->reference_number ?? '') === ($data['reference_number'] ?? '')
            && ($existing->note ?? '') === ($data['note'] ?? '')
            && (int) ($existing->daily_sale_id ?? 0) === (int) ($data['daily_sale_id'] ?? 0)
            && ($existing->source ?? 'manual') === ($data['source'] ?? 'manual')
            && (int) ($existing->financial_account_id ?? 0) === (int) ($data['financial_account_id'] ?? 0);

        if (! $signedMatch && ! $legacyMatch) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['This key was already used with different debt transaction details.'],
            ]);
        }

        return $existing;
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
