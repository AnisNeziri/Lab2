<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductionReadinessService;
use App\Services\SystemIntegrityService;
use App\Support\Release;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductionController extends Controller
{
    public function readiness(ProductionReadinessService $service)
    {
        $report = $service->inspect();
        return response()->json(['status' => $report['status'] === 'healthy' ? 'ready' : 'unavailable', 'checks' => array_map(fn ($check) => array_intersect_key($check, array_flip(['key', 'status'])), $report['checks'])], $report['status'] === 'healthy' ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    public function diagnostics(ProductionReadinessService $service, SystemIntegrityService $integrity)
    {
        $snapshot = $integrity->snapshot();
        $report = $service->inspect();
        // Explicit allowlist: never serialize config, environment, job payloads,
        // exception strings, file paths, logs or private company documents.
        return response()->json([
            'release' => Release::metadata(), 'environment' => app()->environment(), 'readiness' => $report,
            'integrity' => ['status' => $snapshot['status'], 'summary' => $snapshot['summary'], 'checks' => array_map(fn ($row) => array_intersect_key($row, array_flip(['key', 'status', 'count'])), $snapshot['checks'])],
            'backup_history' => array_map(fn ($run) => ['operation' => $run['operation'], 'type' => $run['backup_type'], 'status' => $run['status'], 'completed_at' => $run['completed_at'], 'verification' => $run['verification_result']], $snapshot['backup_history']),
            'recent_errors' => Schema::hasTable('maintenance_health') ? DB::table('maintenance_health')->where('company_id', auth()->user()->company_id)->where('status', 'failed')->get(['task', 'last_attempt_at', 'error_code']) : [],
        ])->header('Cache-Control', 'no-store, private');
    }
}
