<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class SafeBroadcast
{
    public static function dispatch(object $event): void
    {
        // Laravel's log broadcaster writes complete business event payloads.
        // A disabled realtime transport must not turn private records into logs.
        if (in_array(config('broadcasting.default'), ['null', 'log'], true)) {
            return;
        }

        try {
            broadcast($event);
        } catch (\Throwable $exception) {
            Log::warning('Broadcast unavailable. Check the configured local broadcast service.', ['error_type'=>class_basename($exception)]);
        }
    }
}
