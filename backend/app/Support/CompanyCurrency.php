<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Auth;

final class CompanyCurrency
{
    public const DEFAULT = 'EUR';

    public const SUPPORTED = ['EUR', 'USD', 'ALL', 'GBP', 'CHF', 'CNY'];

    public static function normalize(mixed $value): string
    {
        $currency = strtoupper(trim((string) ($value ?? '')));

        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : self::DEFAULT;
    }

    public static function forCompanyId(?int $companyId): string
    {
        if (! $companyId) {
            return self::DEFAULT;
        }

        return self::normalize(Company::query()->whereKey($companyId)->value('base_currency'));
    }

    /**
     * Keep the established currencies available while allowing a tenant's
     * configured ISO-like base currency at downstream inventory boundaries.
     *
     * @return list<string>
     */
    public static function accepted(?string $baseCurrency = null): array
    {
        return array_values(array_unique([
            ...self::SUPPORTED,
            self::normalize($baseCurrency),
        ]));
    }

    public static function current(): string
    {
        return self::forCompanyId(Auth::user()?->company_id);
    }
}
