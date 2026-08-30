<?php

namespace App\Console\Commands;

use App\Services\InventoryExpiryAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncInventoryExpiryAlertsCommand extends Command
{
    protected $signature = 'inventory:sync-expiry-alerts {--company=}';

    protected $description = 'Create, update, and resolve deduplicated inventory expiry alerts.';

    public function handle(InventoryExpiryAlertService $alerts): int
    {
        $companyIds = filled($this->option('company'))
            ? collect([(int) $this->option('company')])
            : DB::table('companies')->orderBy('id')->pluck('id');

        foreach ($companyIds as $companyId) {
            $result = $alerts->syncCompany((int) $companyId);
            $this->line("Company {$companyId}: {$result['created']} created, {$result['updated']} updated, {$result['resolved']} resolved.");
        }

        return self::SUCCESS;
    }
}
