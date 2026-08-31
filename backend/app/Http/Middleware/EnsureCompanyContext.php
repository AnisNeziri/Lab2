<?php

namespace App\Http\Middleware;

use App\Services\InventoryExpiryAlertService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EnsureCompanyContext
{
    public function __construct(private readonly InventoryExpiryAlertService $expiryAlerts) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $companyId = $request->user()?->company_id;
        if (! $companyId) {
            return new JsonResponse([
                'message' => 'Select an explicit company context before using company data.',
            ], 403);
        }

        // The desktop runtime has no permanent scheduler and a web scheduler
        // may have been offline. The first tenant request catches alerts up;
        // the durable state and cache throttle make this safe and unobtrusive.
        $cacheKey = "inventory-expiry-sync:{$companyId}";
        if (Cache::add($cacheKey, true, now()->addMinutes(10))) {
            try {
                $this->expiryAlerts->syncCompany((int) $companyId);
            } catch (\Throwable $exception) {
                Cache::forget($cacheKey);
                Log::warning('Inventory expiry startup catch-up failed.', [
                    'company_id' => (int) $companyId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $next($request);
    }
}
