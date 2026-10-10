<?php

namespace App\Console\Commands;

use App\Services\ProductionReadinessService;
use Illuminate\Console\Command;

class ProductionWorker extends Command
{
    protected $signature = 'aims:work {queue=default} {--once}';
    protected $description = 'Run a bounded, supervised database queue with release-specific heartbeat';

    public function handle(ProductionReadinessService $readiness): int
    {
        $queue = $this->argument('queue');
        if (! in_array($queue, ['default', 'supply-optimizer', 'strategic-simulation'], true)) { $this->error('Unknown queue.'); return self::FAILURE; }
        // One bounded process per supervisor cycle: new code/config is loaded
        // after queue:restart and every cycle. Never re-enter with stale schema.
        $readiness->heartbeat($queue);
        $status = $this->call('queue:work', ['connection' => 'database', '--queue' => $queue, '--tries' => $queue === 'default' ? 3 : 1, '--timeout' => $queue === 'default' ? 60 : 240, '--sleep' => 2, '--max-time' => 50, '--stop-when-empty' => (bool) $this->option('once')]);
        if ($status === self::SUCCESS) $readiness->heartbeat($queue);
        return $status;
    }
}
