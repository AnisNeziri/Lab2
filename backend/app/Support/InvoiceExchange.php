<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceExchange
{
    public static function snapshot(array $input, string $date): array
    {
        $rate = Money::normalizeDecimal($input['exchange_rate'] ?? 0, 8);
        $source = trim($input['exchange_rate_source'] ?? '');
        $asOf = isset($input['exchange_rate_date']) ? CarbonImmutable::parse($input['exchange_rate_date'])->toDateString() : null;
        if (! $asOf || $asOf > $date || Money::compareDecimal($rate, 0) <= 0 || $source === '') {
            throw ValidationException::withMessages(['exchange_rate' => ['Save a positive EUR conversion rate, its effective date and supporting reference before posting.']]);
        }

        return ['rate' => $rate, 'date' => $asOf, 'source' => $source, 'base_currency' => 'EUR'];
    }

    public static function jsonAmount(string $selector): string
    {
        return 'CAST('.DB::connection()->getQueryGrammar()->wrap($selector).' AS DECIMAL(18,2))';
    }
}
