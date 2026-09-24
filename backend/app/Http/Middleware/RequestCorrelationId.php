<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = (string) $request->header('X-Request-ID');
        $requestId = preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $candidate) ? $candidate : (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        Log::withContext([
            'request_id' => $requestId,
            'user_id' => $request->user()?->id,
            'company_id' => $request->user()?->company_id,
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
