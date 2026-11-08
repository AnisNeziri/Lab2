<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = Auth::user();

        if (! $user || ! in_array($user->role, $roles, true)
            || ($user->role === 'superadmin' && $user->company_id !== null)) {
            return response()->json([
                'message' => 'Unauthorized. Insufficient permissions.',
            ], 403);
        }

        return $next($request);
    }
}
