<?php

namespace App\Jobs;

use App\Models\Shipment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class RefreshActiveShipmentsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function handle(): void
    {
        if (config('system.operation_mode') === 'offline') {
            return;
        }

        Shipment::withoutGlobalScopes()
            ->whereNull('archived_at')
            ->whereNotIn('status', ['delivered'])
            ->orderBy('id')
            ->chunkById(50, function ($shipments) {
                foreach ($shipments as $shipment) {
                    RefreshShipmentJob::dispatch($shipment->id);
                }
            });
    }
}
