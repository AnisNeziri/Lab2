<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class SafeBroadcast
{
    public static function dispatch(object $event): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        try {
            broadcast($event);
        } catch (\Throwable $exception) {
            Log::warning('Broadcast unavailable. Check the configured local broadcast service.', ['error_type'=>class_basename($exception)]);
        }
    }
}
