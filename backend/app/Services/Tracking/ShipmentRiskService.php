<?php

namespace App\Services\Tracking;

use App\Models\Shipment;
use Carbon\Carbon;

class ShipmentRiskService
{
    public function __construct(private readonly GeoCalculator $geo) {}

    public function assess(Shipment $shipment): string
    {
        if ($shipment->status === 'delayed') {
            return 'high_risk';
        }

        if (in_array($shipment->status, ['delivered', 'out_for_delivery'], true)) {
            return 'on_schedule';
        }

        if (! $shipment->eta || ! $shipment->departed_at) {
            return 'on_schedule';
        }

        $hasCurrentAndDestination = $this->hasCoordinates(
            $shipment->current_lat,
            $shipment->current_lng,
            $shipment->destination_lat,
            $shipment->destination_lng,
        );
        $hasPlannedRoute = $this->hasCoordinates(
            $shipment->origin_lat,
            $shipment->origin_lng,
            $shipment->destination_lat,
            $shipment->destination_lng,
        );

        // AISStream can provide an ETA before either route endpoint is known.
        // Missing coordinates must never be coerced to (0, 0), which would
        // manufacture a delay/risk result for a real vessel.
        if (! $hasCurrentAndDestination || ! $hasPlannedRoute) {
            return $shipment->eta->isPast() ? 'potential_delay' : 'on_schedule';
        }

        $distance = $shipment->distance_to_port_km ?? $this->geo->distanceKm(
            $shipment->current_lat,
            $shipment->current_lng,
            $shipment->destination_lat,
            $shipment->destination_lng
        );

        $expectedEta = $this->geo->estimateEta(
            $shipment->departed_at,
            (float) $shipment->origin_lat,
            (float) $shipment->origin_lng,
            (float) $shipment->destination_lat,
            (float) $shipment->destination_lng,
        );

        $eta = Carbon::parse($shipment->eta);
        $delayHours = $eta->diffInHours($expectedEta, false);

        if ($delayHours >= 48 || ($distance > 200 && in_array($shipment->status, ['arrived_at_port', 'in_transit'], true) && $eta->isPast())) {
            return 'high_risk';
        }

        if ($delayHours >= 12 || $eta->isPast()) {
            return 'potential_delay';
        }

        return 'on_schedule';
    }

    private function hasCoordinates(mixed $lat1, mixed $lng1, mixed $lat2, mixed $lng2): bool
    {
        return $this->coordinate($lat1, -90, 90) !== null
            && $this->coordinate($lng1, -180, 180) !== null
            && $this->coordinate($lat2, -90, 90) !== null
            && $this->coordinate($lng2, -180, 180) !== null;
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return $value >= $minimum && $value <= $maximum ? $value : null;
    }
}
