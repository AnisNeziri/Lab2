<?php

namespace App\Console\Commands;

use App\Services\ProductionReadinessService;
use Illuminate\Console\Command;

class ProductionCheck extends Command
{
    protected $signature = 'aims:production-check {--json} {--before-start : Omit worker heartbeats before launching supervised processes}';
    protected $description = 'Validate production dependencies and safe configuration without exposing secrets';

    public function handle(ProductionReadinessService $service): int
    {
        $result = $service->inspect(! $this->option('before-start'));
        if ($this->option('json')) $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else { $this->info('AIMS '.$result['version'].' readiness: '.$result['status']); $this->table(['Check', 'State', 'Action'], array_map(fn ($row) => array_values($row), $result['checks'])); }
        return $result['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
    }
}
