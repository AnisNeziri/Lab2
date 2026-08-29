<?php

namespace App\Services\Tracking;

use Carbon\Carbon;

class GeoCalculator
{
    public function interpolate(float $lat1, float $lng1, float $lat2, float $lng2, float $t): array
    {
        $t = max(0, min(1, $t));

        return [
            round($lat1 + ($lat2 - $lat1) * $t, 6),
            round($lng1 + ($lng2 - $lng1) * $t, 6),
        ];
    }

    public function distanceKm(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): int
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return 0;
        }

        $earth = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    public function estimateEta(Carbon $departedAt, float $originLat, float $originLng, float $destLat, float $destLng, float $knots = 14): Carbon
    {
        $distance = $this->distanceKm($originLat, $originLng, $destLat, $destLng);
        $hours = $distance / max($knots * 1.852, 1);

        return $departedAt->copy()->addHours((int) ceil($hours));
    }

    public function progress(Carbon $departedAt, Carbon $eta): float
    {
        $totalSeconds = max($departedAt->diffInSeconds($eta), 1);
        $elapsed = min($departedAt->diffInSeconds(now()), $totalSeconds);

        return $elapsed / $totalSeconds;
    }

    public function locationLabel(float $progress): string
    {
        if ($progress < 0.2) {
            return 'South China Sea — outbound';
        }
        if ($progress < 0.45) {
            return 'Indian Ocean passage';
        }
        if ($progress < 0.75) {
            return 'Mediterranean approach';
        }
        if ($progress < 0.95) {
            return 'Approaching destination waters';
        }

        return 'Near destination port';
    }
}
