<?php

namespace App\Services;

use App\Models\FxReferenceRate;
use App\Models\IntegrationProvider;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class EcbFxRateProvider
{
    public function __construct(private readonly IntegrationExecutionService $integrations) {}

    public function referenceRate(int $companyId, string $base, string $quote, ?string $rateDate = null): FxReferenceRate
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);
        $existing = FxReferenceRate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->when($rateDate, fn ($query) => $query->whereDate('rate_date', $rateDate))
            ->latest('rate_date')
            ->first();
        if ($existing) {
            return $existing;
        }

        $provider = IntegrationProvider::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'category' => 'fx_rates', 'provider_key' => 'ecb'],
            ['display_name' => 'European Central Bank', 'enabled' => true, 'health_state' => 'unknown'],
        );

        $feed = Cache::remember('integrations:ecb:daily:v1', now()->addHours(6), fn () =>
            $this->integrations->run($provider, 'reference_rates', $rateDate, function (): array {
                $response = Http::timeout(12)->get(config(
                    'services.ecb.daily_url',
                    'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml'
                ));
                $response->throw();

                return $this->parse($response->body());
            })
        );

        if ($rateDate && $rateDate !== $feed['date']) {
            throw ValidationException::withMessages([
                'rate_date' => ['The requested historical ECB rate is not cached. Enter a manual historical rate instead.'],
            ]);
        }

        $rates = ['EUR' => '1'] + $feed['rates'];
        if (! isset($rates[$base], $rates[$quote])) {
            throw ValidationException::withMessages(['currency' => ['ECB does not publish one of the selected currencies.']]);
        }
        $rate = (string) BigDecimal::of((string) $rates[$quote])
            ->dividedBy(BigDecimal::of((string) $rates[$base]), 10, RoundingMode::HALF_UP);

        return FxReferenceRate::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $companyId, 'base_currency' => $base,
                'quote_currency' => $quote, 'rate_date' => $feed['date'], 'source' => 'ECB',
            ],
            ['integration_provider_id' => $provider->id, 'rate' => $rate],
        );
    }

    private function parse(string $xml): array
    {
        if (! preg_match('/<Cube\s+time=["\']([^"\']+)["\']/', $xml, $dateMatch)) {
            throw new \RuntimeException('ECB response did not contain a rate date.');
        }
        preg_match_all(
            '/<Cube\s+currency=["\']([A-Z]{3})["\']\s+rate=["\']([0-9.]+)["\']\s*\/?\s*>/',
            $xml,
            $matches,
            PREG_SET_ORDER,
        );
        $rates = [];
        foreach ($matches as $match) {
            $rates[$match[1]] = $match[2];
        }
        if ($rates === []) {
            throw new \RuntimeException('ECB response did not contain reference rates.');
        }

        return ['date' => $dateMatch[1], 'rates' => $rates];
    }
}
