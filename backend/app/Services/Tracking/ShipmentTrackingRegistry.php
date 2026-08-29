<?php

namespace App\Services\Tracking;

use App\Contracts\Tracking\ShipmentTrackingProviderInterface;
use InvalidArgumentException;

class ShipmentTrackingRegistry
{
    /** @var array<string, ShipmentTrackingProviderInterface> */
    private array $providers = [];

    public function register(ShipmentTrackingProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function resolve(?string $preferred = null): ShipmentTrackingProviderInterface
    {
        $name = $preferred ?? config('tracking.shipment_provider', 'disabled');

        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }

        throw new InvalidArgumentException(
            $name === 'disabled'
                ? 'Automatic parcel tracking is disabled until a real provider is configured.'
                : "Shipment tracking provider [{$name}] is not registered."
        );
    }
}
