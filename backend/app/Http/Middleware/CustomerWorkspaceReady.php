<?php
namespace App\Http\Middleware;
use Closure;
class CustomerWorkspaceReady
{
    public function handle($request,Closure $next){app(\App\Services\CustomerWorkspaceService::class)->permit();return $next($request);}
}
