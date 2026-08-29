<?php

namespace App\Jobs;

use App\Models\Shipment;
use App\Services\Tracking\MyShipmentTrackingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RefreshShipmentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $shipmentId) {}

    public function backoff(): array
    {
        return config('tracking.retry.backoff_seconds', [30, 120, 300]);
    }

    public function handle(MyShipmentTrackingService $tracking): void
    {
        $shipment = Shipment::withoutGlobalScopes()->find($this->shipmentId);

        if (! $shipment || $shipment->archived_at) {
            return;
        }

        $tracking->refresh($shipment);
        $shipment->forceFill(['last_refreshed_at' => now()])->save();
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
