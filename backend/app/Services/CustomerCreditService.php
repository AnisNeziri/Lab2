<?php

namespace App\Services;

use App\Models\Customer;
use App\Support\Money;

class CustomerCreditService
{
    /** Shared oldest-due-first allocation used by exposure and order payment state. */
    public function obligations(Customer $customer): \Illuminate\Support\Collection
    {
        $debts = $customer->debtTransactions()
            ->whereIn('type', ['debt_added', 'opening_balance', 'positive_adjustment'])
            ->whereNull('reversed_transaction_id')
            ->whereDoesntHave('reversals')
            // Obligations with a real due date are allocated first. Null due
            // dates must not consume payments ahead of an overdue obligation.
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $payments = $customer->debtTransactions()
            ->whereIn('type', ['payment', 'negative_adjustment', 'return', 'cancellation'])
            ->whereNull('reversed_transaction_id')
            ->whereDoesntHave('reversals')
            ->sum('amount');

        $unallocatedPayments = Money::normalize($payments);
        return $debts->map(function($debt)use(&$unallocatedPayments){
            $allocated=Money::minimum($debt->amount,$unallocatedPayments);
            $debt->setAttribute('outstanding_amount',Money::subtract($debt->amount,$allocated));
            $unallocatedPayments=Money::subtract($unallocatedPayments,$allocated);
            return $debt;
        });
    }

    public function exposure(Customer $customer): array
    {
        $today = now('Europe/Tirane')->startOfDay();
        $debts=$this->obligations($customer);
        $buckets = [
            'current' => '0.00',
            '1_30' => '0.00',
            '31_60' => '0.00',
            '61_90' => '0.00',
            '90_plus' => '0.00',
        ];
        $oldestOverdue = null;

        foreach ($debts as $debt) {
            $openAmount = $debt->outstanding_amount;

            if (Money::compare($openAmount, '0.00') <= 0) {
                continue;
            }

            $daysOverdue = $debt->due_date
                ? $debt->due_date->diffInDays($today, false)
                : 0;
            $bucket = match (true) {
                $daysOverdue <= 0 => 'current',
                $daysOverdue <= 30 => '1_30',
                $daysOverdue <= 60 => '31_60',
                $daysOverdue <= 90 => '61_90',
                default => '90_plus',
            };
            $buckets[$bucket] = Money::add($buckets[$bucket], $openAmount);

            if ($daysOverdue > 0 && (! $oldestOverdue || $debt->due_date->lt($oldestOverdue))) {
                $oldestOverdue = $debt->due_date;
            }
        }

        $overdue = Money::add(
            $buckets['1_30'],
            $buckets['31_60'],
            $buckets['61_90'],
            $buckets['90_plus'],
        );
        $committed = \App\Models\SalesOrder::query()->where('customer_id',$customer->id)->sum('committed_amount');
        $exposure = Money::add(Money::subtract($customer->current_debt, $customer->current_credit),$committed);
        $limit = $customer->credit_limit === null
            ? null
            : Money::normalize($customer->credit_limit);

        // A zero limit is valid and means that any positive net exposure needs
        // approval. Its utilization is undefined, so never divide by zero.
        $utilization = $limit === null || Money::compare($limit, '0.00') <= 0
            ? null
            : (float) Money::divide(
                Money::multiply(Money::maximum($exposure, '0.00'), '100'),
                $limit,
            );

        return [
            'current_debt' => Money::normalize($customer->current_debt),
            'committed_exposure' => Money::normalize($committed),
            'advance' => Money::normalize($customer->current_credit),
            'overdue' => $overdue,
            'aging' => $buckets,
            'oldest_overdue_date' => $oldestOverdue?->toDateString(),
            'total_exposure' => $exposure,
            'credit_limit' => $limit,
            'available_credit' => $limit === null ? null : Money::subtract($limit, $exposure),
            'utilization_percent' => $utilization,
        ];
    }
}
