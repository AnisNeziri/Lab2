<?php

namespace App\Services\Kosovo;

use App\Models\AccountingPeriod;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CorporateTaxDisposition;
use App\Models\InvoiceProfile;
use App\Services\AccountingService;
use App\Services\PermissionService;
use App\Support\CompanyClock;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CorporateTaxDispositionService
{
    public function __construct(private readonly CorporateTaxEvidence $evidence, private readonly AccountingService $accounting) {}

    private function fiscalStartMonth(AccountingPeriod $period): int
    {
        return (int) (Company::query()->whereKey($period->company_id)->value('fiscal_year_start_month') ?: 1);
    }

    public function periodScope(AccountingPeriod $period): string
    {
        $from = $period->starts_at->toImmutable();
        $fiscalStart = $from->startOfYear()->addMonths($this->fiscalStartMonth($period) - 1);
        if ($from->lt($fiscalStart)) {
            $fiscalStart = $fiscalStart->subYear();
        }
        $fiscalEnd = $fiscalStart->addYear()->subDay();
        if ($from->isSameDay($fiscalStart) && $period->ends_at->isSameDay($fiscalEnd)) {
            return 'annual';
        }

        // A month, stub or multi-year range containing the fiscal boundary
        // cannot finalize the year through the interim close path.
        return $period->ends_at->gte($fiscalEnd) ? 'unsupported_year_end' : 'interim';
    }

    public function latest(AccountingPeriod $period): ?CorporateTaxDisposition
    {
        return CorporateTaxDisposition::query()->where('accounting_period_id', $period->id)->latest('id')->first();
    }

    private function scope(AccountingPeriod $period): array
    {
        return [
            'version' => 2,
            'company' => (int) $period->company_id,
            'period' => (int) $period->id,
            'from' => $period->starts_at->toDateString(),
            'to' => $period->ends_at->toDateString(),
            'reopen_revision' => $period->tax_review_revision ?? 0,
            'fiscal_start_month' => $this->fiscalStartMonth($period),
            'jurisdiction' => InvoiceProfile::query()->value('country_code'),
            'currency' => CompanyCurrency::forCompanyId($period->company_id),
        ];
    }

    public function state(AccountingPeriod $period): array
    {
        $scope = $this->periodScope($period);
        if ($scope !== 'annual') {
            return ['state' => $scope === 'interim' ? 'not_annual' : $scope, 'scope' => $scope, 'decision' => null];
        }
        $decision = $this->latest($period);
        if (! $decision) {
            return ['state' => 'missing_policy', 'scope' => $scope, 'decision' => null];
        }
        $policy = $decision->policy;
        $state = $policy['regime'];
        if ($decision->historical || ! hash_equals($decision->basis, $this->basis($period, $policy))) {
            $state = 'stale_policy';
        } elseif ($state === 'actual_income' && ! $this->supportsWorksheet($period, $policy['jurisdiction'])) {
            $state = 'unsupported';
        }

        return ['state' => $state, 'scope' => $scope, 'decision' => $decision];
    }

    private function supportsWorksheet(AccountingPeriod $period, string $jurisdiction): bool
    {
        $start = $period->starts_at->toImmutable();

        return $jurisdiction === 'XK' && InvoiceProfile::query()->value('country_code') === 'XK'
            && CompanyCurrency::forCompanyId($period->company_id) === 'EUR'
            && $start->month === 1 && $start->day === 1 && $period->ends_at->isSameDay($start->addYear()->subDay());
    }

    private function basis(AccountingPeriod $period, array $policy): string
    {
        $scope = $this->scope($period);
        if (in_array($policy['regime'], ['inapplicable', 'alternative_reviewed'], true)) {
            $scope['ledger'] = $this->evidence->digest($period);
        }

        return CorporateTaxEvidence::hash(['scope' => $scope, 'policy' => $policy]);
    }

    public function approve(AccountingPeriod $period, array $data): CorporateTaxDisposition
    {
        $permissions = app(PermissionService::class);
        $role = Auth::user()->role;
        abort_unless($permissions->roleHasPermission($role, 'accounting.periods.close')
            && $permissions->roleHasPermission($role, 'accounting.journal.post'), 403);

        return DB::transaction(function () use ($period, $data) {
            DB::table('companies')->where('id', Auth::user()->company_id)->lockForUpdate()->first();
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($this->periodScope($period) !== 'annual' || $period->status !== 'open' || $period->ends_at->isAfter(CompanyClock::today()->endOfDay())) {
                throw ValidationException::withMessages(['period' => ['Use an open annual period that has ended.']]);
            }
            $policy = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data);
            $jurisdiction = strtoupper($policy['jurisdiction']);
            $registered = InvoiceProfile::query()->value('country_code');
            if ($registered && $jurisdiction !== $registered) {
                throw ValidationException::withMessages(['jurisdiction' => ['The tax jurisdiction must agree with the company invoice profile.']]);
            }
            if ($policy['effective_from'] > $period->starts_at->toDateString() || $policy['effective_to'] < $period->ends_at->toDateString()) {
                throw ValidationException::withMessages(['effective_to' => ['The evidence must cover the full annual period.']]);
            }
            $policy['jurisdiction'] = $jurisdiction;
            $policy['recorded_tax'] = isset($policy['recorded_tax']) ? Money::normalize($policy['recorded_tax']) : null;
            if (in_array($policy['regime'], ['inapplicable', 'alternative_reviewed'], true)) {
                $expected = $policy['regime'] === 'inapplicable' ? '0.00' : $policy['recorded_tax'];
                $profit = $this->accounting->profitAndLoss($period->starts_at->toDateString(), $period->ends_at->toDateString());
                if ($expected === null || $expected !== $profit['income_tax']) {
                    throw ValidationException::withMessages(['recorded_tax' => ['The approved treatment must reconcile to current tax in the posted books.']]);
                }
            }
            $basis = $this->basis($period, $policy);
            $latest = $this->latest($period);
            if ($latest && ! $latest->historical && hash_equals($latest->basis, $basis)) {
                return $latest;
            }
            $decision = CorporateTaxDisposition::create(['accounting_period_id' => $period->id, 'policy' => $policy, 'basis' => $basis, 'approved_by' => Auth::id()]);
            ActivityLog::create([
                'company_id' => $period->company_id, 'user_id' => Auth::id(), 'action' => 'accounting.corporate_tax.disposition_recorded',
                'entity' => 'CorporateTaxDisposition', 'entity_id' => $decision->id, 'old_value' => $latest?->policy,
                'new_value' => $policy, 'description' => 'Annual corporate-tax disposition recorded.',
            ]);

            return $decision;
        });
    }
}
