<?php
namespace App\Http\Middleware;

use App\Services\CatalogReadAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/** Defense against nested Product models in otherwise authorized API responses. */
final class ConfidentialCatalogResponse
{
    public function handle(Request $request, Closure $next)
    {
        $response=$next($request);
        if($response instanceof JsonResponse && $response->isSuccessful() && auth()->user()?->company_id){
            $access=app(CatalogReadAccess::class);
            if(!$access->costs($access->permissions()))$response->setData($access->redact($response->getData(true)));
        }
        return $response;
    }
}
