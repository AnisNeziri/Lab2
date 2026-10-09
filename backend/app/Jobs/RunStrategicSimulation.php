<?php

namespace App\Jobs;

use App\Models\StrategicSimulation;
use App\Services\StrategicSimulationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunStrategicSimulation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $simulationId) {}

    public function handle(): void
    {
        app(StrategicSimulationService::class)->run($this->simulationId);
    }

    public function failed(?\Throwable $e): void
    {
        StrategicSimulation::withoutGlobalScopes()->whereKey($this->simulationId)->whereIn('status', ['QUEUED', 'RUNNING'])->update(['status' => 'FAILED', 'error' => 'Simulation worker interrupted. Re-run without changing the original baseline.']);
    }
}
