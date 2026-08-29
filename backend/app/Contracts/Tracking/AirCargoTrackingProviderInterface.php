<?php

namespace App\Contracts\Tracking;

interface AirCargoTrackingProviderInterface
{
    public function name(): string;

    public function supports(string $trackingNumber): bool;

    public function validate(string $trackingNumber): bool;

    public function lookup(string $trackingNumber): ?array;
}
