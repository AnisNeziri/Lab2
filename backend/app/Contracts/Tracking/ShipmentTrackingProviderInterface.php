<?php

namespace App\Contracts\Tracking;

use App\Models\Shipment;

interface ShipmentTrackingProviderInterface
{
    public function name(): string;

    public function supports(string $trackingNumber, string $transportMode = 'sea'): bool;

    public function validate(string $trackingNumber): bool;

    public function lookup(string $trackingNumber, string $transportMode = 'sea'): ?array;

    public function refresh(Shipment $shipment): array;
}
