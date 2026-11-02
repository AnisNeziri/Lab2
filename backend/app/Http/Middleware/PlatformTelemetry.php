<?php

namespace App\Http\Middleware;

use App\Services\Platform\PlatformTrace;
use Closure;
use Illuminate\Http\Request;

class PlatformTelemetry
{
    public function handle(Request $request, Closure $next)
    {
        $trace = app(PlatformTrace::class);
        $trace->begin($request->header('traceparent'));
        try {
            $response = $trace->measure('http.request', function () use ($next, $request, $trace) {
                $response = $next($request);
                $trace->measure('http.result', fn () => null, ['request_id'=>$request->attributes->get('request_id'),'support_reference'=>$request->attributes->get('support_reference'),'route' => $request->route()?->uri() ?? 'unmatched', 'method' => $request->method(), 'http_status' => $response->getStatusCode()] + $trace->stats());

                return $response;
            });
            $response->headers->set('traceparent', $trace->context()['traceparent']);

            return $response;
        } finally {
            $trace->flush();
        }
    }
}
