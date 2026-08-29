<?php

namespace App\Services\Tracking;

use App\Models\Shipment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class VesselLookupService
{
    public function lookup(string $rawIdentifier, int $companyId): array
    {
        if (! config('tracking.external_enabled')) {
            throw new InvalidArgumentException('Live vessel lookup is not enabled on this installation.');
        }

        [$identifier, $identifierType] = $this->normalizeIdentifier($rawIdentifier);
        $local = $this->localSnapshot($identifier, $identifierType, $companyId);
        $apiKey = trim((string) config('tracking.vessel_lookup.api_key'));
        // AIMS already persists the latest useful AIS state. Return it
        // immediately instead of delaying the user behind an optional details
        // provider request.
        $snapshot = $local;
        $providerFailure = false;

        if (! $snapshot && $apiKey !== '' && config('tracking.vessel_lookup.provider') === 'vesselapi') {
            try {
                $snapshot = Cache::remember(
                    "tracking.vesselapi.{$identifierType}.{$identifier}",
                    now()->addMinutes(max(1, (int) config('tracking.vessel_lookup.cache_minutes', 10))),
                    fn () => $this->lookupWithVesselApi($identifier, $identifierType, $apiKey),
                );
            } catch (InvalidArgumentException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $providerFailure = true;
                Log::warning('VesselAPI lookup failed.', [
                    'identifier_type' => $identifierType,
                    'identifier' => $identifier,
                    'exception' => $exception,
                ]);
                $snapshot = $local;
            }
        } else {
            $snapshot = $local;
        }

        if (! $snapshot && $identifierType === 'imo') {
            if ($providerFailure) {
                throw new InvalidArgumentException(
                    'The IMO lookup provider is temporarily unavailable. Please try again, or search using the vessel MMSI to start AIS tracking immediately.'
                );
            }

            throw new InvalidArgumentException(
                'IMO lookup needs a VesselAPI key because AISStream subscriptions accept MMSI only. Configure VESSELAPI_API_KEY, or search using the vessel MMSI.'
            );
        }

        if (! $snapshot) {
            $snapshot = [
                'mmsi' => $identifier,
                'imo' => null,
                'name' => null,
                'details_provider' => 'aisstream',
                'details_status' => 'pending',
                'message' => $providerFailure
                    ? 'MMSI accepted. The details provider is temporarily unavailable; verified vessel details and position will appear from AISStream.'
                    : 'MMSI accepted. Verified vessel details and position will appear after the first AISStream message is received.',
                'vessel_details' => [],
            ];
        }

        if (empty($snapshot['mmsi']) || ! preg_match('/^\d{9}$/', (string) $snapshot['mmsi'])) {
            throw new InvalidArgumentException('The provider did not return a valid nine-digit MMSI for this vessel, so live AIS tracking cannot be started.');
        }

        $token = (string) Str::uuid();
        Cache::put($this->tokenKey($token), [
            'company_id' => $companyId,
            'vessel' => $snapshot,
        ], now()->addMinutes(15));

        return [
            'lookup_token' => $token,
            'identifier' => $identifier,
            'identifier_type' => $identifierType,
            'vessel' => $snapshot,
        ];
    }

    public function resolveToken(string $token, int $companyId): array
    {
        $cached = Cache::get($this->tokenKey(trim($token)));
        if (! is_array($cached)
            || (int) ($cached['company_id'] ?? 0) !== $companyId
            || ! is_array($cached['vessel'] ?? null)) {
            throw new InvalidArgumentException('This vessel search has expired. Search the MMSI or IMO again.');
        }

        return $cached['vessel'];
    }

    private function lookupWithVesselApi(string $identifier, string $identifierType, string $apiKey): array
    {
        $baseUrl = config('tracking.vessel_lookup.base_url', 'https://api.vesselapi.com');
        $query = ['filter.idType' => $identifierType];
        $client = Http::withToken($apiKey)->acceptJson()->timeout(10)->retry(2, 250, throw: false);

        try {
            $staticResponse = $client->get("{$baseUrl}/v1/vessel/{$identifier}", $query);
            $this->assertProviderResponse($staticResponse, $identifier);

            $positionResponse = $client->get("{$baseUrl}/v1/vessel/{$identifier}/position", $query + ['filter.sat' => 'false']);
            $etaResponse = $client->get("{$baseUrl}/v1/vessel/{$identifier}/eta", $query);
        } catch (ConnectionException $exception) {
            throw new \RuntimeException('The vessel lookup provider could not be reached.', previous: $exception);
        }

        $vessel = (array) $staticResponse->json('vessel', []);
        $position = $positionResponse->successful() ? (array) $positionResponse->json('vesselPosition', []) : [];
        $voyage = $etaResponse->successful() ? (array) $etaResponse->json('vesselEta', []) : [];
        $mmsi = (string) ($vessel['mmsi'] ?? $position['mmsi'] ?? $voyage['mmsi'] ?? '');
        $imo = (string) ($vessel['imo'] ?? $position['imo'] ?? $voyage['imo'] ?? '');
        $latitude = $this->coordinate($position['latitude'] ?? null, -90, 90);
        $longitude = $this->coordinate($position['longitude'] ?? null, -180, 180);
        $destination = trim((string) ($voyage['destination'] ?? $voyage['destination_port'] ?? '')) ?: null;

        return [
            'mmsi' => $mmsi ?: null,
            'imo' => $imo ?: null,
            'name' => trim((string) ($vessel['name_ais'] ?? $vessel['name'] ?? $position['vessel_name'] ?? '')) ?: null,
            'call_sign' => trim((string) ($vessel['call_sign'] ?? '')) ?: null,
            'vessel_type' => trim((string) ($vessel['vessel_subtype'] ?? $vessel['vessel_type'] ?? '')) ?: null,
            'flag_country' => trim((string) ($vessel['country'] ?? $vessel['country_code'] ?? '')) ?: null,
            'year_built' => is_numeric($vessel['year_built'] ?? null) ? (int) $vessel['year_built'] : null,
            'current_lat' => $latitude,
            'current_lng' => $longitude,
            'speed_knots' => is_numeric($position['sog'] ?? null) ? (float) $position['sog'] : null,
            'course' => is_numeric($position['cog'] ?? null) ? (float) $position['cog'] : null,
            'position_updated_at' => $this->dateString($position['timestamp'] ?? $position['processed_timestamp'] ?? null),
            'destination_port' => $destination,
            'destination_code' => trim((string) ($voyage['destination_port'] ?? '')) ?: null,
            'eta' => $this->dateString($voyage['eta'] ?? null),
            'details_provider' => 'vesselapi',
            'details_status' => 'verified',
            'message' => $latitude !== null && $longitude !== null
                ? 'Verified vessel details and latest provider position found.'
                : 'Verified vessel details found. Waiting for a live AIS position.',
            'vessel_details' => [
                'static' => $vessel,
                'position' => $position,
                'voyage' => $voyage,
            ],
        ];
    }

    private function assertProviderResponse(Response $response, string $identifier): void
    {
        if ($response->successful() && is_array($response->json('vessel'))) {
            return;
        }

        $providerMessage = trim((string) $response->json('error.message'));
        if ($response->status() === 404) {
            throw new InvalidArgumentException("No vessel was found for {$identifier}.");
        }
        if (in_array($response->status(), [400, 401], true)) {
            throw new InvalidArgumentException($providerMessage ?: 'The vessel identifier or lookup-provider configuration is invalid.');
        }

        throw new \RuntimeException($providerMessage ?: "The vessel lookup provider returned HTTP {$response->status()}.");
    }

    private function localSnapshot(string $identifier, string $identifierType, int $companyId): ?array
    {
        $shipment = Shipment::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where($identifierType, $identifier)
            ->latest('details_updated_at')
            ->latest('id')
            ->first();

        if (! $shipment || ! preg_match('/^\d{9}$/', (string) $shipment->mmsi)) {
            return null;
        }

        return [
            'mmsi' => (string) $shipment->mmsi,
            'imo' => $shipment->imo ? (string) $shipment->imo : null,
            'name' => $shipment->vessel_name,
            'call_sign' => $shipment->call_sign,
            'vessel_type' => $shipment->vessel_type,
            'flag_country' => $shipment->flag_country,
            'year_built' => $shipment->year_built,
            'current_lat' => $shipment->current_lat,
            'current_lng' => $shipment->current_lng,
            'speed_knots' => $shipment->speed_knots,
            'course' => $shipment->course,
            'heading' => $shipment->heading,
            'navigation_status' => $shipment->navigation_status,
            'position_updated_at' => $shipment->position_updated_at?->toIso8601String(),
            'destination_port' => $shipment->destination_port,
            'eta' => $shipment->eta?->toIso8601String(),
            'details_provider' => $shipment->details_provider ?: 'aisstream',
            'details_status' => 'verified',
            'message' => 'Saved verified vessel details found. Live AIS tracking will continue in the background.',
            'vessel_details' => $shipment->vessel_details ?: [],
        ];
    }

    private function normalizeIdentifier(string $rawIdentifier): array
    {
        $value = preg_replace('/\D/', '', strtoupper(trim($rawIdentifier)));
        if (preg_match('/^\d{9}$/', $value)) {
            return [$value, 'mmsi'];
        }
        if (preg_match('/^\d{7}$/', $value) && $this->validImo($value)) {
            return [$value, 'imo'];
        }

        throw new InvalidArgumentException('Enter a valid 9-digit MMSI or a valid 7-digit IMO number.');
    }

    private function validImo(string $imo): bool
    {
        $sum = 0;
        for ($index = 0; $index < 6; $index++) {
            $sum += ((int) $imo[$index]) * (7 - $index);
        }

        return $sum % 10 === (int) $imo[6];
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return $number >= $minimum && $number <= $maximum ? $number : null;
    }

    private function dateString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || strtotime($value) === false) {
            return null;
        }

        return $value;
    }

    private function tokenKey(string $token): string
    {
        return 'tracking.vessel-lookup.'.$token;
    }
}
