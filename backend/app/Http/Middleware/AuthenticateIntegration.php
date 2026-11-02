<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Services\Platform\IntegrationGateway;
use Closure;
use Illuminate\Http\Request;

class AuthenticateIntegration
{
    public function handle(Request $r, Closure $next)
    {
        $account = app(IntegrationGateway::class)->authenticate((string) $r->bearerToken());
        $start = microtime(true);
        $status = 500;
        try {
            $response = $next($r);
            $status = $response->getStatusCode();

            return $response;
        } finally {
            try {
                ActivityLog::create(['company_id' => $account->company_id, 'user_id' => $account->user_id, 'action' => $status < 400 ? 'integration.api_succeeded' : 'integration.api_denied', 'entity' => 'ServiceAccount', 'entity_id' => $account->id, 'description' => 'Versioned integration API request', 'new_value' => ['request_id' => $r->attributes->get('request_id'), 'duration_ms' => round((microtime(true) - $start) * 1000, 3), 'status' => $status]]);
            } catch (\Throwable $e) {
            }
        }
    }
}
