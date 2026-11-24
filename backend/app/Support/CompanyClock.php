<?php
namespace App\Support;

use App\Models\Company;
use Carbon\CarbonImmutable;

final class CompanyClock
{
    public static function timezone(?Company $company = null): string
    {
        return ($company ?? auth()->user()?->company)?->timezone ?: (config('production.timezone') ?: config('app.timezone', 'UTC'));
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }
}
