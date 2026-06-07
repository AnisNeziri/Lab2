<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckPermission
{
    public function __construct(
        private PermissionService $permissions
    ) {}

    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = Auth::user();

        if (! $user || ! $this->permissions->roleHasPermission($user->role, $permission)) {
            return response()->json([
                'message' => 'Unauthorized. Insufficient permissions.',
            ], 403);
        }

        return $next($request);
    }
}
