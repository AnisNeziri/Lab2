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
        if (! $companyId || $request->user()?->role === 'superadmin') {
            return new JsonResponse([
                'message' => 'An authorized company account is required to access company data.',
            ], 403);
        }

        if (!$request->isMethodSafe() && !$request->is('api/onboarding*','api/intelligence-assistant/ask','api/procurement/purchase-comparison')) {
            app(\App\Services\Onboarding\OnboardingGuard::class)->assertOperational();
        }

        // The desktop runtime has no permanent scheduler and a web scheduler
        // may have been offline. The first tenant request catches alerts up;
        // the durable state and cache throttle make this safe and unobtrusive.
        if ($request->is('api/procurement/purchase-comparison')) return $next($request);
        $cacheKey = "inventory-expiry-sync:{$companyId}";
        try { $runCatchup = Cache::add($cacheKey, true, now()->addMinutes(10)); }
        catch (\Throwable) { $runCatchup = false; }
        if ($runCatchup) {
            try {
                $this->expiryAlerts->syncCompany((int) $companyId);
            } catch (\Throwable $exception) {
                try { Cache::forget($cacheKey); } catch (\Throwable) { }
                Log::warning('Inventory expiry startup catch-up failed.', [
                    'company_id' => (int) $companyId,
                    'error_code' => 'expiry_catchup_failed',
                ]);
            }
        }

        return $next($request);
    }
}
