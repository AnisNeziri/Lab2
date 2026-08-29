<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

class RefreshVesselFleetCacheJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function handle(): void
    {
        if (config('system.operation_mode') === 'offline') {
            return;
        }

        Cache::forget('tracking.vessels.'.md5(json_encode([])));
    }
}
