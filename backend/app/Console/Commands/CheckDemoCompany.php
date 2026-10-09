<?php

namespace App\Console\Commands;

use App\Services\Synthetic\SyntheticDatabase;
use Illuminate\Console\Command;

class CheckDemoCompany extends Command
{
    protected $signature = 'aims:check-demo-company {--seed=20261006}';
    protected $description = 'Read-only ownership/completion check for an isolated synthetic company.';

    public function handle(SyntheticDatabase $database): int
    {
        try {
            $seed = filter_var($this->option('seed'), FILTER_VALIDATE_INT);
            if ($seed === false) throw new \RuntimeException('A valid integer seed is required.');
            $database->assertReady($seed);
            $report = json_decode(file_get_contents($database->path($seed).'.report.json'), true, flags: JSON_THROW_ON_ERROR);
            if (($report['configuration']['seed'] ?? null) !== $seed || ! ($report['integrity']['synthetic_tenant_isolated'] ?? false) || ! ($report['integrity']['inventory_verified'] ?? false) || ! ($report['integrity']['financial_verified'] ?? false)) throw new \RuntimeException('The matching synthetic validation report did not pass integrity.');
            $this->info('Owned synthetic company is complete and its report passed integrity.');
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
