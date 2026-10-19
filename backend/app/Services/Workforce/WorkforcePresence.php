<?php

namespace App\Services\Workforce;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reuse only within one request, keyed by connection/company; never cache authorization or balances. */
final class WorkforcePresence
{
    private static function key(int $company): string
    {
        return 'dc4_presence:'.DB::getDefaultConnection().':'.DB::connection()->getDatabaseName().':'.$company;
    }

    public static function exists(): bool
    {
        $company = (int) Auth::user()?->company_id;
        if (! $company) {
            return false;
        }
        $attributes = request()->attributes;
        $key = self::key($company);
        if (! $attributes->has($key)) {
            // Configured controls must still be checked against GL when the employee ledger is empty.
            $employees = DB::table('employees')->where('company_id', $company)->selectRaw('1 present');
            $configured = DB::table('accounting_posting_mappings')->where('company_id', $company)->where('mapping_key', 'like', 'payroll_%')->selectRaw('1 present');
            $attributes->set($key, self::schemaExists() && DB::query()->fromSub($employees->unionAll($configured), 'workforce_presence')->exists());
        }

        return (bool) $attributes->get($key);
    }

    public static function schemaExists(): bool
    {
        $key = 'dc4_schema:'.DB::getDefaultConnection().':'.DB::connection()->getDatabaseName();
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, Schema::hasTable('employees'));
        }

        return (bool) request()->attributes->get($key);
    }

    public static function forget(int $company): void
    {
        request()->attributes->remove(self::key($company));
    }
}
