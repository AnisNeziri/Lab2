<?php

namespace App\Services\Tracking;

use App\Contracts\Tracking\AirCargoTrackingProviderInterface;
use InvalidArgumentException;

class AirCargoTrackingRegistry
{
    /** @var array<string, AirCargoTrackingProviderInterface> */
    private array $providers = [];

    public function register(AirCargoTrackingProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function resolve(): AirCargoTrackingProviderInterface
    {
        $name = config('tracking.air_cargo_provider', 'disabled');

        if (! isset($this->providers[$name])) {
            throw new InvalidArgumentException(
                $name === 'disabled'
                    ? 'Automatic air-cargo tracking is disabled until a real provider is configured.'
                    : "Air cargo tracking provider [{$name}] is not registered."
            );
        }

        return $this->providers[$name];
    }
}
