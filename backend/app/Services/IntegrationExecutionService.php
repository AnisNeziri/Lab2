<?php

namespace App\Services;

use App\Models\IntegrationOperationLog;
use App\Models\IntegrationProvider;
use Illuminate\Validation\ValidationException;
use Throwable;

class IntegrationExecutionService
{
    public function run(
        IntegrationProvider $provider,
        string $operation,
        ?string $reference,
        callable $callback,
    ): mixed {
        if (! $provider->enabled) {
            throw ValidationException::withMessages([
                'provider' => ["{$provider->display_name} is disabled."],
            ]);
        }

        $log = IntegrationOperationLog::withoutGlobalScopes()->create([
            'company_id' => $provider->company_id,
            'integration_provider_id' => $provider->id,
            'operation' => $operation,
            'status' => 'started',
            'request_reference' => $reference,
            'started_at' => now(),
            'attempts' => 1,
        ]);

        try {
            $result = $callback();
            $log->update(['status' => 'succeeded', 'completed_at' => now(), 'error' => null]);
            $provider->update([
                'health_state' => 'healthy', 'last_success_at' => now(),
                'last_data_received_at' => now(), 'last_error' => null,
            ]);

            return $result;
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            $log->update([
                'status' => 'failed', 'completed_at' => now(), 'error' => $message,
                'next_retry_at' => now()->addMinutes(5),
            ]);
            $provider->update([
                'health_state' => 'error', 'last_failure_at' => now(), 'last_error' => $message,
            ]);
            throw $exception;
        }
    }
}
