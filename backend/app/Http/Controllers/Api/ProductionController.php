<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductionReadinessService;
use App\Support\Release;

class ProductionController extends Controller
{
    public function readiness(ProductionReadinessService $service)
    {
        $report = $service->inspect();
        return response()->json(['status' => $report['status'] === 'healthy' ? 'ready' : 'unavailable', 'checks' => array_map(fn ($check) => array_intersect_key($check, array_flip(['key', 'status'])), $report['checks'])], $report['status'] === 'healthy' ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    public function diagnostics(ProductionReadinessService $service)
    {
        app(\App\Services\Security\SecurityEvents::class)->record('platform.diagnostics_read');
        $report = $service->inspect();
        // Explicit allowlist: never serialize config, environment, job payloads,
        // exception strings, file paths, logs or private company documents.
        return response()->json([
            'release' => Release::metadata(), 'environment' => app()->environment(), 'readiness' => $report,
        ])->header('Cache-Control', 'no-store, private');
    }
}
