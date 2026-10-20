<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesOrder;
use App\Support\CompanyClock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CustomerCreditService
{
    /** Shared oldest-due-first allocation used by exposure and order payment state. */
    public function obligations(Customer $customer): Collection
    {
        return app(ReceivablePositionQueries::class)->obligations($customer);
    }

    /** Read-only Action Center projection; same oldest-due-first allocation as obligations(). */
    public function overdueForCustomers(Collection $customers): array
    {
        if ($customers->isEmpty()) {
            return [];
        }
        $ids = $customers->filter(fn ($c) => (int) $c->company_id === (int) auth()->user()->company_id)->pluck('id')->all();
        if (! $ids) {
            return [];
        }
        $groups = app(ReceivablePositionQueries::class)->agingForCustomers($ids);
        $out = [];
        foreach ($ids as $id) {
            $r = $groups->get($id);
            $amount = Money::add($r?->days_1_30 ?? 0, $r?->days_31_60 ?? 0, $r?->days_61_90 ?? 0, $r?->days_90_plus ?? 0);
            $out[$id] = ['overdue' => $amount, 'oldest_overdue_date' => Money::compare($amount, 0) > 0 ? substr((string) $r->oldest_due, 0, 10) : null];
        }

        return $out;
    }

    public function exposure(Customer $customer): array
    {
        $today = CompanyClock::now()->startOfDay();
        $r = app(ReceivablePositionQueries::class)->agingForCustomers([$customer->id])->get($customer->id);
        $buckets = ['current' => Money::normalize($r?->current ?? 0), '1_30' => Money::normalize($r?->days_1_30 ?? 0),
            '31_60' => Money::normalize($r?->days_31_60 ?? 0), '61_90' => Money::normalize($r?->days_61_90 ?? 0),
            '90_plus' => Money::normalize($r?->days_90_plus ?? 0), 'unknown_due' => Money::normalize($r?->unknown_due ?? 0)];
        $overdue = Money::add($buckets['1_30'], $buckets['31_60'], $buckets['61_90'], $buckets['90_plus']);
        $oldestOverdue = Money::compare($overdue, 0) > 0 && $r?->oldest_due ? CarbonImmutable::parse($r->oldest_due) : null;
        $committed = SalesOrder::query()->where('customer_id', $customer->id)->sum('committed_amount');
        $exposure = Money::add(Money::subtract($customer->current_debt, $customer->current_credit), $committed);
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
