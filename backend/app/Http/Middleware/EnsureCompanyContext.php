<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnsureCompanyContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->user()?->company_id) {
            return new JsonResponse([
                'message' => 'Select an explicit company context before using company data.',
            ], 403);
        }

        return $next($request);
    }
}
