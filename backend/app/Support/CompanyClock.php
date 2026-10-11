<?php
namespace App\Support;

use Carbon\CarbonImmutable;

final class CompanyClock
{
    public static function timezone(): string
    {
        return config('production.timezone') ?: config('app.timezone', 'UTC');
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
