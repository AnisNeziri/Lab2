<?php

namespace App\Http\Middleware;

use App\Services\PilotTelemetryService;
use Closure;
use Illuminate\Http\Request;

class PilotTelemetry
{
    public function handle(Request $request, Closure $next)
    {
        $started = microtime(true);
        $response = $next($request);
        // Default-off path performs no pilot database queries.
        if (config('pilot.company_id')) {
            app(PilotTelemetryService::class)->record($request, $response, $started);
        }

        return $response;
    }
}
