<?php

namespace App\Console\Commands;

use App\Jobs\RefreshActiveShipmentsJob;
use Illuminate\Console\Command;

class RefreshShipmentTrackingCommand extends Command
{
    protected $signature = 'shipments:refresh-tracking';

    protected $description = 'Queue a refresh for all active, non-delivered shipments.';

    public function handle(): int
    {
        if (config('system.operation_mode') === 'offline') {
            $this->info('Offline mode is active; no external tracking refresh was requested.');

            return self::SUCCESS;
        }

        RefreshActiveShipmentsJob::dispatch();
        $this->info('Active shipment tracking refresh queued.');

        return self::SUCCESS;
    }
}
