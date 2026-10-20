<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use App\Models\CustomerSettlementAllocation;
use App\Support\CompanyClock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit attribution of existing settlements; never moves money or posts AR. */
class CustomerSettlementService
{
    public function allocate(CustomerDebtTransaction $payment, array $input): array
    {
        $this->permit();
        $d = validator($input, ['idempotency_key' => 'required|string|max:100', 'allocation_date' => 'required|date',
            'reason' => 'required|string|min:5|max:1000', 'allocations' => 'required|array|min:1|max:100',
            'allocations.*.debt_transaction_id' => 'required|integer|distinct', 'allocations.*.amount' => 'required|numeric|decimal:0,2|min:0.01'])->validate();
        usort($d['allocations'], fn ($a, $b) => $a['debt_transaction_id'] <=> $b['debt_transaction_id']);

        return DB::transaction(function () use ($payment, $d) {
            $this->lockCompany();
            $customer = Customer::query()->lockForUpdate()->findOrFail($payment->customer_id);
            $payment = CustomerDebtTransaction::query()->where('customer_id', $customer->id)->lockForUpdate()->findOrFail($payment->id);
            $fingerprint = hash('sha256', json_encode(['allocate', $payment->id, $d], JSON_THROW_ON_ERROR));
            if ($existing = $this->replay($d['idempotency_key'], $fingerprint)) {
                return $existing;
            }
            $on = CarbonImmutable::parse($d['allocation_date'])->toDateString();
            $this->date($on);
            if ($payment->transaction_date->toDateString() > $on || $payment->reversed_transaction_id || $payment->reversals()->exists() || ! in_array($payment->type, ['payment', 'negative_adjustment', 'return', 'cancellation'], true)) {
                throw ValidationException::withMessages(['payment' => 'Choose an effective unreversed settlement.']);
            }
            $capacity = $this->capacity($payment, false);
            if(Money::compare($capacity,0)>0&&app(ReceivablePositionQueries::class)->receiptHasReclassifiedFunding($payment))throw ValidationException::withMessages(['payment'=>'Earlier receipt funds were reclassified to customer advances after cancelling settled debt. Review that correction lineage before assigning this receipt again.']);
            $used = CustomerSettlementAllocation::where('settlement_transaction_id', $payment->id)->whereNull('released_on')->sum('amount');
            $requested = Money::decimal(array_sum(array_map(fn ($a) => Money::minor($a['amount']), $d['allocations'])));
            if (Money::compare($requested, Money::subtract($capacity, $used)) > 0) {
                throw ValidationException::withMessages(['allocations' => 'The selection exceeds the receivable portion of this payment. Its advance portion remains separate.']);
            }
            $ids = [];
            foreach ($d['allocations'] as $selection) {
                $debt = CustomerDebtTransaction::query()->where('customer_id', $customer->id)->lockForUpdate()->findOrFail($selection['debt_transaction_id']);
                if ($debt->transaction_date->toDateString() > $on || $debt->reversed_transaction_id || $debt->reversals()->exists() || ! in_array($debt->type, ['debt_added', 'positive_adjustment', 'opening_balance'], true)) {
                    throw ValidationException::withMessages(['allocations' => 'Choose an effective receivable from the same customer.']);
                }
                $reserved = CustomerSettlementAllocation::where('debt_transaction_id', $debt->id)->whereNull('released_on')->sum('amount');
                if (Money::compare($selection['amount'], Money::subtract($this->capacity($debt, true), $reserved)) > 0) {
                    throw ValidationException::withMessages(['allocations' => 'This receivable already has explicit allocations covering the selected amount.']);
                }
                $row = CustomerSettlementAllocation::create(['company_id' => $customer->company_id, 'customer_id' => $customer->id,
                    'settlement_transaction_id' => $payment->id, 'debt_transaction_id' => $debt->id, 'amount' => Money::normalize($selection['amount']),
                    'allocated_on' => $on, 'allocated_by' => Auth::id(), 'reason' => trim($d['reason'])]);
                $ids[] = $row->id;
            }
            $this->remember($d['idempotency_key'], $fingerprint, 'allocate', $ids);
            $this->audit('customer.payment.allocated', $customer, ['payment_id' => $payment->id, 'allocation_ids' => $ids, 'amount' => $requested, 'date' => $on, 'reason' => $d['reason']]);

            return $this->result($ids);
        });
    }

    public function release(CustomerSettlementAllocation $allocation, array $input): array
    {
        $this->permit();
        $d = validator($input, ['idempotency_key' => 'required|string|max:100', 'allocation_date' => 'required|date', 'reason' => 'required|string|min:5|max:1000'])->validate();

        return DB::transaction(function () use ($allocation, $d) {
            $this->lockCompany();
            $customer = Customer::query()->lockForUpdate()->findOrFail($allocation->customer_id);
            $allocation = CustomerSettlementAllocation::query()->lockForUpdate()->findOrFail($allocation->id);
            $fingerprint = hash('sha256', json_encode(['release', $allocation->id, $d], JSON_THROW_ON_ERROR));
            if ($existing = $this->replay($d['idempotency_key'], $fingerprint)) {
                return $existing;
            }
            $on = CarbonImmutable::parse($d['allocation_date'])->toDateString();
            $this->date($on);
            if ($on < $allocation->allocated_on->toDateString()) {
                throw ValidationException::withMessages(['allocation_date' => 'A release cannot precede its allocation.']);
            }
            if ($allocation->released_on) {
                throw ValidationException::withMessages(['allocation' => 'This allocation is already released. Refresh its history.']);
            }
            $allocation->update(['released_on' => $on, 'released_at' => now(), 'released_by' => Auth::id(), 'release_reason' => trim($d['reason'])]);
            $this->remember($d['idempotency_key'], $fingerprint, 'release', [$allocation->id]);
            $this->audit('customer.payment.unallocated', $customer, ['allocation_id' => $allocation->id, 'date' => $on, 'reason' => $d['reason']]);

            return $this->result([$allocation->id]);
        });
    }

    public function capacity(CustomerDebtTransaction $transaction, bool $increase): string
    {
        // Preserve declared legacy amounts when historical snapshots were not
        // populated; the aging projection separately flags these for review.
        if (Money::compare($transaction->balance_before, $transaction->balance_after) === 0 && Money::compare($transaction->credit_before, $transaction->credit_after) === 0) {
            return Money::normalize($transaction->amount);
        }
        $delta = $increase ? Money::subtract($transaction->balance_after, $transaction->balance_before) : Money::subtract($transaction->balance_before, $transaction->balance_after);

        return Money::maximum($delta, 0);
    }

    public function workbench(Customer $customer): array
    {
        $payments = $customer->debtTransactions()->whereIn('type', ['payment', 'negative_adjustment', 'return', 'cancellation'])->whereNull('reversed_transaction_id')->whereDoesntHave('reversals')
            ->orderByDesc('transaction_date')->orderByDesc('id')->paginate(50, ['id', 'customer_id', 'type', 'amount', 'transaction_date', 'reference_number', 'balance_before', 'balance_after', 'credit_before', 'credit_after']);
        $sums = CustomerSettlementAllocation::whereIn('settlement_transaction_id', $payments->getCollection()->pluck('id'))->whereNull('released_on')->selectRaw('settlement_transaction_id,SUM(amount) total')->groupBy('settlement_transaction_id')->pluck('total', 'settlement_transaction_id');
        $retiredAfterId=$customer->debtTransactions()->whereNotNull('reversed_transaction_id')->where('metadata->settled_debt_credit','>',0)->max('id')??0;
        $payments->through(fn ($p) => [...$p->toArray(), 'receivable_portion' => $this->capacity($p, false), 'explicitly_allocated' => Money::normalize($sums[$p->id] ?? 0),
            'remaining_for_review' => $p->id<$retiredAfterId?'0.00':Money::maximum(Money::subtract($this->capacity($p, false), $sums[$p->id] ?? 0), 0),
            'funding_review_required'=>$p->id<$retiredAfterId&&Money::compare($this->capacity($p,false),0)>0,
            'advance_created' => Money::maximum(Money::subtract($p->credit_after, $p->credit_before), 0)]);
        $history = CustomerSettlementAllocation::where('customer_id', $customer->id)->with(['debt:id,reference_number,transaction_date', 'settlement:id,reference_number,transaction_date', 'allocator:id,name', 'releaser:id,name'])->latest('id')->paginate(50, ['*'], 'history_page');
        $debts = app(ReceivablePositionQueries::class)->rows(null, [$customer->id])->where('capacity', '>', 0)->orderBy('transaction_date')->orderBy('id')->paginate(50, ['*'], 'debts_page');

        return ['customer' => $customer->only(['id', 'name', 'current_debt', 'current_credit']), 'payments' => $payments, 'debts' => $debts, 'history' => $history,
            'policy' => 'Existing receivable settlements follow oldest known due date unless explicitly attributed here. Allocating or releasing changes attribution, not cash, revenue, AR or advances.'];
    }

    private function date(string $date): void
    {
        if ($date > CompanyClock::today()->toDateString()) {
            throw ValidationException::withMessages(['allocation_date' => 'Allocate effective payments through today; planned future activity stays separate.']);
        }
        app(AccountingService::class)->assertDateOpen($date);
    }

    private function permit(): void
    {
        abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'accounting.ar.allocate'), 403);
    }

    private function lockCompany(): void
    {
        DB::table('companies')->where('id', Auth::user()->company_id)->lockForUpdate()->first();
    }

    private function replay(string $key, string $fingerprint): ?array
    {
        $row = DB::table('customer_allocation_requests')->where('company_id', Auth::user()->company_id)->where('idempotency_key', $key)->first();
        if (! $row) {
            return null;
        }
        abort_unless(hash_equals($row->fingerprint, $fingerprint), 409, 'This allocation request key belongs to a different selection.');

        return $this->result(json_decode($row->result, true, 512, JSON_THROW_ON_ERROR));
    }

    private function remember(string $key, string $fingerprint, string $action, array $ids): void
    {
        DB::table('customer_allocation_requests')->insert(['company_id' => Auth::user()->company_id, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'action' => $action, 'result' => json_encode($ids, JSON_THROW_ON_ERROR), 'user_id' => Auth::id(), 'created_at' => now()]);
    }

    private function result(array $ids): array
    {
        return CustomerSettlementAllocation::whereIn('id', $ids)->orderBy('id')->get()->all();
    }

    private function audit(string $action, Customer $customer, array $facts): void
    {
        ActivityLog::create(['company_id' => $customer->company_id, 'user_id' => Auth::id(), 'action' => $action, 'entity' => 'Customer', 'entity_id' => $customer->id, 'description' => str_replace('.', ' ', $action), 'new_value' => $facts]);
    }
}
