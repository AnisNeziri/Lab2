<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\Models\FinancialAccountTransfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancialAccountService
{
    private const INFLOW_TYPES = ['inflow', 'transfer_in', 'adjustment_in', 'refund_in'];
    private const OUTFLOW_TYPES = ['outflow', 'transfer_out', 'adjustment_out', 'refund_out'];

    public function accounts(): array
    {
        return FinancialAccount::query()->withCount('transactions')->orderByDesc('is_active')->orderBy('name')
            ->get()->map(fn (FinancialAccount $account) => $this->decorate($account))->all();
    }

    public function createAccount(array $data): FinancialAccount
    {
        return DB::transaction(function () use ($data) {
            $account = FinancialAccount::create([
                ...$data,
                'company_id' => Auth::user()->company_id,
                'currency' => strtoupper($data['currency'] ?? 'EUR'),
            ]);
            $this->audit('financial_account.created', $account, null, $account->toArray());

            return $this->decorate($account);
        });
    }

    public function updateAccount(FinancialAccount $account, array $data): FinancialAccount
    {
        return DB::transaction(function () use ($account, $data) {
            $locked = FinancialAccount::query()->lockForUpdate()->findOrFail($account->id);
            $old = $locked->toArray();
            if (array_key_exists('opening_balance', $data) && $locked->transactions()->exists()) {
                unset($data['opening_balance'], $data['opening_date']);
            }
            $locked->update($data);
            $this->audit('financial_account.updated', $locked, $old, $locked->fresh()->toArray());

            return $this->decorate($locked->fresh());
        });
    }

    public function transactions(FinancialAccount $account, array $filters): LengthAwarePaginator
    {
        $query = $account->transactions()->with('creator:id,name')->latest('transaction_date')->latest('id');
        if (! empty($filters['from'])) $query->whereDate('transaction_date', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('transaction_date', '<=', $filters['to']);
        if (! empty($filters['type'])) $query->where('type', $filters['type']);
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('description', 'like', "%{$search}%")
                ->orWhere('reference_number', 'like', "%{$search}%")
                ->orWhere('counterparty', 'like', "%{$search}%"));
        }

        return $query->paginate($filters['per_page'] ?? 30);
    }

    public function post(FinancialAccount|int $account, array $data): FinancialAccountTransaction
    {
        return DB::transaction(function () use ($account, $data) {
            $accountId = $account instanceof FinancialAccount ? $account->id : $account;
            $locked = FinancialAccount::query()->lockForUpdate()->findOrFail($accountId);
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['financial_account_id' => ['The selected money account is inactive.']]);
            }
            $type = $data['type'];
            if (! in_array($type, [...self::INFLOW_TYPES, ...self::OUTFLOW_TYPES], true)) {
                throw ValidationException::withMessages(['type' => ['The selected money movement type is invalid.']]);
            }
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
            }
            $key = (string) ($data['idempotency_key'] ?? Str::uuid());
            $existing = FinancialAccountTransaction::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                $same = (int) $existing->financial_account_id === (int) $locked->id
                    && $existing->type === $type && abs((float) $existing->amount - $amount) < .005
                    && (string) $existing->transaction_date?->toDateString() === (string) $data['transaction_date'];
                if (! $same) {
                    throw ValidationException::withMessages(['idempotency_key' => ['This key is already used for a different money movement.']]);
                }

                return $existing;
            }
            if (str_starts_with($type, 'adjustment_') && blank($data['description'] ?? null)) {
                throw ValidationException::withMessages(['description' => ['A reason is required for an account adjustment.']]);
            }

            $transaction = FinancialAccountTransaction::create([
                'company_id' => $locked->company_id,
                'financial_account_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'currency' => $locked->currency,
                'transaction_date' => $data['transaction_date'],
                'source_type' => $data['source_type'] ?? 'manual',
                'source_id' => $data['source_id'] ?? null,
                'related_transaction_id' => $data['related_transaction_id'] ?? null,
                'counterparty' => $data['counterparty'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => 'posted',
                'idempotency_key' => $key,
                'created_by' => Auth::id(),
            ]);
            $this->audit('financial_transaction.posted', $transaction, null, $transaction->toArray());

            return $transaction->load(['account:id,name,type,currency', 'creator:id,name']);
        });
    }

    public function transfer(array $data): FinancialAccountTransfer
    {
        return DB::transaction(function () use ($data) {
            if ((int) $data['source_account_id'] === (int) $data['destination_account_id']) {
                throw ValidationException::withMessages(['destination_account_id' => ['Choose a different destination account.']]);
            }
            $ids = [(int) $data['source_account_id'], (int) $data['destination_account_id']];
            sort($ids);
            $accounts = FinancialAccount::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            $source = $accounts->get((int) $data['source_account_id']);
            $destination = $accounts->get((int) $data['destination_account_id']);
            if (! $source || ! $destination || ! $source->is_active || ! $destination->is_active) {
                throw ValidationException::withMessages(['accounts' => ['Both transfer accounts must be active and owned by the company.']]);
            }
            if ($source->currency !== $destination->currency) {
                throw ValidationException::withMessages(['destination_account_id' => ['Transfers currently require accounts with the same currency.']]);
            }
            $key = (string) ($data['idempotency_key'] ?? Str::uuid());
            $existing = FinancialAccountTransfer::query()->where('idempotency_key', $key)->first();
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['Transfer amount must be greater than zero.']]);
            }
            if ($existing) {
                $same = (int) $existing->source_account_id === (int) $source->id
                    && (int) $existing->destination_account_id === (int) $destination->id
                    && abs((float) $existing->amount - $amount) < .005
                    && $existing->transfer_date?->toDateString() === (string) $data['transfer_date'];
                if (! $same) {
                    throw ValidationException::withMessages(['idempotency_key' => ['This key is already used for a different account transfer.']]);
                }

                return $existing->load(['sourceAccount', 'destinationAccount']);
            }
            $transfer = FinancialAccountTransfer::create([
                ...$data, 'amount' => $amount, 'company_id' => $source->company_id,
                'idempotency_key' => $key, 'created_by' => Auth::id(),
            ]);
            $out = $this->post($source, [
                'type' => 'transfer_out', 'amount' => $amount, 'transaction_date' => $data['transfer_date'],
                'source_type' => 'financial_account_transfer', 'source_id' => $transfer->id,
                'counterparty' => $destination->name, 'reference_number' => $data['reference_number'] ?? null,
                'description' => $data['notes'] ?? 'Transfer to '.$destination->name,
                'idempotency_key' => $key.'-out',
            ]);
            $in = $this->post($destination, [
                'type' => 'transfer_in', 'amount' => $amount, 'transaction_date' => $data['transfer_date'],
                'source_type' => 'financial_account_transfer', 'source_id' => $transfer->id,
                'related_transaction_id' => $out->id, 'counterparty' => $source->name,
                'reference_number' => $data['reference_number'] ?? null,
                'description' => $data['notes'] ?? 'Transfer from '.$source->name,
                'idempotency_key' => $key.'-in',
            ]);
            $out->update(['related_transaction_id' => $in->id]);

            return $transfer->load(['sourceAccount', 'destinationAccount']);
        });
    }

    public function reverse(FinancialAccountTransaction $transaction, string $reason): FinancialAccountTransaction
    {
        return DB::transaction(function () use ($transaction, $reason) {
            $current = FinancialAccountTransaction::query()->findOrFail($transaction->id);
            $ids = array_values(array_filter([$current->id, $current->related_transaction_id]));
            sort($ids);
            $lockedTransactions = FinancialAccountTransaction::query()
                ->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            $locked = $lockedTransactions->get($current->id);
            if ($locked->status !== 'posted') return $locked;

            $targets = collect([$locked]);
            if ($locked->source_type === 'financial_account_transfer' && $locked->related_transaction_id) {
                $related = $lockedTransactions->get($locked->related_transaction_id);
                if ($related
                    && $related->source_type === 'financial_account_transfer'
                    && (int) $related->source_id === (int) $locked->source_id) {
                    $targets->push($related);
                }
            }
            foreach ($targets->unique('id') as $target) {
                if ($target->status !== 'posted') continue;
                $old = $target->toArray();
                $target->update([
                    'status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => Auth::id(),
                    'reversal_reason' => trim($reason),
                ]);
                $this->audit('financial_transaction.reversed', $target, $old, $target->fresh()->toArray(), $reason);
            }

            return $locked->fresh('account');
        });
    }

    public function balance(FinancialAccount $account): float
    {
        $in = (float) $account->transactions()->where('status', 'posted')->whereIn('type', self::INFLOW_TYPES)->sum('amount');
        $out = (float) $account->transactions()->where('status', 'posted')->whereIn('type', self::OUTFLOW_TYPES)->sum('amount');

        return round((float) $account->opening_balance + $in - $out, 2);
    }

    private function decorate(FinancialAccount $account): FinancialAccount
    {
        $account->setAttribute('current_balance', $this->balance($account));

        return $account;
    }

    private function audit(string $action, object $entity, mixed $old, mixed $new, ?string $reason = null): void
    {
        ActivityLog::create([
            'company_id' => Auth::user()->company_id, 'user_id' => Auth::id(),
            'action' => $action, 'entity' => class_basename($entity), 'entity_id' => $entity->id,
            'description' => $reason ?: str_replace('.', ' ', $action),
            'old_value' => $old ? json_encode($old) : null, 'new_value' => $new ? json_encode($new) : null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
