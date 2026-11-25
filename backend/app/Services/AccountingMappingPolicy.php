<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingPostingMapping;
use App\Models\JournalEntry;
use App\Services\Workforce\PayrollService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class AccountingMappingPolicy
{
    public function protected(string $key): bool
    {
        return str_starts_with($key, 'fixed_asset_') || str_starts_with($key, 'corporate_tax_') || str_starts_with($key, 'payroll_');
    }

    private function definitions(): array
    {
        $definitions = [];
        $defaults = array_column(AccountingService::DEFAULTS, null, 0);
        foreach (AccountingService::MAPPINGS as $key => $code) {
            if ($this->protected($key)) {
                [, $name, $type, , $normal] = $defaults[$code];
                $definitions[$key] = [$name, $type, $normal];
            }
        }
        foreach (PayrollService::ACCOUNTS as $key => [, $name, $type]) {
            $definitions['payroll_'.$key] = [$name, $type, $type === 'liability' ? 'credit' : 'debit'];
        }

        return $definitions;
    }

    public function problem(string $key, ?AccountingAccount $account, array $responsibilities): ?string
    {
        $definitions = $this->definitions();
        $definition = $definitions[$key] ?? null;
        if (! $account || (int) $account->company_id !== (int) Auth::user()->company_id) {
            return 'missing_account';
        }
        if (! $account->is_posting || ! $account->is_active
            || ($definition && [$account->type, $account->normal_balance] !== array_slice($definition, 1))
            || ($key === 'corporate_tax_expense' && $account->statement_group !== 'income_tax')) {
            return 'account_type';
        }
        foreach ($responsibilities as $other) {
            if ($other !== $key && ($definition || $this->protected($other))) {
                return 'shared_account';
            }
        }
        if ($definition) {
            // Existing explicit mappings designate custom accounts. Known reserved
            // account names must still agree with the designated purpose.
            $name = mb_strtolower(trim($account->name));
            foreach ($definitions as $other => [$reserved]) {
                if ($other !== $key && $name === mb_strtolower($reserved)) {
                    return 'account_purpose';
                }
            }
        }

        return null;
    }

    public function assertCompatible(string $key, ?AccountingAccount $account, ?array $responsibilities = null): void
    {
        $responsibilities ??= $account
            ? AccountingPostingMapping::query()->where('accounting_account_id', $account->id)->pluck('mapping_key')->all()
            : [];
        if ($this->problem($key, $account, $responsibilities)) {
            throw ValidationException::withMessages([$key => ['Select a dedicated, compatible posting account in accounting setup. Review prior journals before changing an existing mapping.']]);
        }
    }

    public function diagnostics(bool $payroll = false): array
    {
        $mappings = AccountingPostingMapping::query()->with('account')->get();
        $responsibilities = $mappings->groupBy('accounting_account_id');
        $byKey = $mappings->keyBy('mapping_key');
        $codes = $payroll ? array_column(PayrollService::ACCOUNTS, 0) : array_values(AccountingService::MAPPINGS);
        $byCode = AccountingAccount::query()->whereIn('code', $codes)->get()->keyBy('code');
        $issues = [];
        foreach ($this->definitions() as $key => [$name]) {
            if (str_starts_with($key, 'payroll_') !== $payroll) {
                continue;
            }
            $account = $byKey->get($key)?->account;
            if (! $account) {
                // A company that has never configured this module need not
                // fix a missing mapping before using unrelated operations.
                $code = $payroll ? PayrollService::ACCOUNTS[substr($key, 8)][0] : AccountingService::MAPPINGS[$key];
                $occupied = $byCode->get($code);
                if ($occupied && ($occupied->name !== $name
                    || $this->problem($key, $occupied, $responsibilities->get($occupied->id, collect())->pluck('mapping_key')->all()))) {
                    $issues[] = ['mapping' => $key, 'code' => $code, 'reason' => 'default_in_use', 'prior_postings' => null];
                }
                continue;
            }
            $problem = $this->problem($key, $account, $responsibilities->get($account->id, collect())->pluck('mapping_key')->all());
            if (! $problem) {
                continue;
            }
            $sources = $payroll ? ['payroll'] : (str_starts_with($key, 'corporate_tax_') ? ['corporate_tax'] : ['fixed_assets', 'supplier_accounting', 'sales', 'returns']);
            $prior = JournalEntry::query()->whereIn('status', ['posted', 'reversed'])->whereIn('source_module', $sources)
                ->whereHas('lines', fn ($q) => $q->where('accounting_account_id', $account->id))->count();
            $issues[] = ['mapping' => $key, 'code' => $account->code, 'reason' => $problem, 'prior_postings' => $prior];
        }

        return $issues;
    }
}
