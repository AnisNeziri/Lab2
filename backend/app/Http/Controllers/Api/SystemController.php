<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SystemController extends Controller
{
    public function mode(): JsonResponse
    {
        $mode = config('system.operation_mode', 'online');
        $parcelProvider = config('tracking.shipment_provider', 'disabled');
        $airProvider = config('tracking.air_cargo_provider', 'disabled');
        $vesselProvider = config('tracking.vessel_provider', 'aisstream');
        $vesselLookupProvider = config('tracking.vessel_lookup.provider', 'vesselapi');
        $externalTrackingEnabled = (bool) config('tracking.external_enabled', $mode === 'online');
        $aisConfigured = $vesselProvider === 'aisstream'
            && filled(config('tracking.aisstream.api_key'));
        $aisEnabled = $externalTrackingEnabled && $aisConfigured;
        $aisConnection = Cache::get('tracking.aisstream.connection', []);
        $aisConnection = is_array($aisConnection) ? $aisConnection : [];
        $connectionUpdatedAt = ! empty($aisConnection['updated_at'])
            ? Carbon::parse($aisConnection['updated_at'])
            : null;
        $connectionFresh = $connectionUpdatedAt?->gte(now()->subMinutes(2)) ?? false;
        $connectionState = $aisEnabled
            ? ($connectionFresh ? ($aisConnection['state'] ?? 'starting') : 'worker_unavailable')
            : ($aisConfigured ? 'network_disabled' : 'not_configured');
        $vesselLookupConfigured = $vesselLookupProvider === 'vesselapi'
            && filled(config('tracking.vessel_lookup.api_key'));
        $vesselLookupEnabled = $externalTrackingEnabled
            && $vesselLookupProvider === 'vesselapi'
            && $vesselLookupConfigured;

        return response()->json([
            'mode' => $mode,
            'online' => $mode === 'online',
            'tracking_message' => $externalTrackingEnabled
                ? 'Configured tracking integrations may refresh silently when an internet connection is available.'
                : 'Inventory and sales remain local; tracking integrations are disabled and saved positions remain available.',
            'tracking' => [
                'external_enabled' => $externalTrackingEnabled,
                'vessel' => [
                    'provider' => $vesselProvider,
                    'configured' => $aisConfigured,
                    'enabled' => $aisEnabled,
                    'status' => $connectionState,
                    'connection_state' => $connectionState,
                    'connection_available' => in_array($connectionState, ['connected', 'updating_subscription'], true),
                    'tracked_mmsis_count' => (int) ($aisConnection['tracked_mmsis_count'] ?? 0),
                    'queued_mmsis_count' => (int) ($aisConnection['overflow_count'] ?? 0),
                    'connection_updated_at' => $connectionUpdatedAt?->toIso8601String(),
                    'last_message_at' => $aisConnection['last_message_at'] ?? null,
                    'lookup_provider' => $vesselLookupProvider,
                    'details_lookup_configured' => $vesselLookupConfigured,
                    'details_lookup_enabled' => $vesselLookupEnabled,
                    'mmsi_lookup_enabled' => $aisEnabled,
                    'imo_lookup_enabled' => $vesselLookupEnabled,
                ],
                'parcel' => [
                    'provider' => $parcelProvider,
                    'enabled' => $externalTrackingEnabled && ! in_array($parcelProvider, ['disabled', 'demo'], true),
                ],
                'air' => [
                    'provider' => $airProvider,
                    'enabled' => $externalTrackingEnabled && ! in_array($airProvider, ['disabled', 'demo'], true),
                ],
            ],
        ]);
    }
}
