<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class MaintenanceHealthService
{
    public function record(int $companyId, string $task, ?string $errorCode = null): void
    {
        $values = [
            'status' => $errorCode === null ? 'healthy' : 'failed',
            'last_attempt_at' => now(),
            'error_code' => $errorCode,
        ];
        if ($errorCode === null) $values['last_success_at'] = now();
        DB::table('maintenance_health')->updateOrInsert(['company_id' => $companyId, 'task' => $task], $values);
    }
}
