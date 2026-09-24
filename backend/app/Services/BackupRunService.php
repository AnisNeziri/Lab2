<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class BackupRunService
{
    public function start(string $operation, string $type, ?array $modules = null): BackupRun
    {
        return BackupRun::query()->create([
            'company_id' => Auth::user()->company_id,
            'user_id' => Auth::id(),
            'operation' => $operation,
            'backup_type' => $type,
            'status' => 'started',
            'modules' => $modules ? array_values($modules) : ['full'],
            'started_at' => now(),
        ]);
    }

    public function complete(BackupRun $run, ?int $sizeBytes, string $verification): void
    {
        $run->update([
            'status' => 'completed',
            'size_bytes' => $sizeBytes,
            'verification_result' => $verification,
            'completed_at' => now(),
            'error_summary' => null,
        ]);
    }

    public function fail(BackupRun $run, \Throwable $error): void
    {
        $run->update([
            'status' => 'failed',
            'verification_result' => 'failed',
            'error_summary' => Str::limit($error->getMessage() ?: class_basename($error), 500, ''),
            'completed_at' => now(),
        ]);
    }
}
