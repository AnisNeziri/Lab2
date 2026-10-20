<?php

namespace App\Http\Middleware;

use App\Services\ProductionReadinessService;
use Closure;

class ProductionConfiguration
{
    public function handle($request, Closure $next)
    {
        if (app()->environment('production') && collect(app(ProductionReadinessService::class)->configuration())->contains('status', 'critical')) {
            return response()->json(['message' => 'AIMS production configuration is incomplete. Ask the administrator to run aims:production-check.', 'status' => 'unavailable'], 503);
        }
        return $next($request);
    }
}
