<?php

namespace App\Console\Commands;

use App\Models\{Customer, JournalEntry, Product, Supplier, User, Warehouse};
use App\Services\{AccountingService, InventoryIntegrityService};
use App\Services\Synthetic\{SyntheticCompanyScenario, SyntheticDatabase, SyntheticValidation};
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Auth, DB};

class ValidateDemoCompany extends Command
{
    protected $signature = 'aims:validate-demo-company {--seed=20261006} {--repair-rounding : Append an audited, bounded correction to this owned synthetic database only} {--refresh-advisory : Refresh current planning and optimizer evidence without backdating or operational changes}';
    protected $description = 'Recheck an already-generated synthetic company without rewriting historical evidence.';

    public function handle(SyntheticDatabase $database): int
    {
        try {
            $seed = filter_var($this->option('seed'), FILTER_VALIDATE_INT);
            if ($seed === false) throw new \RuntimeException('Invalid seed.');
            $database->assertReady($seed);
            $database->connect($seed);
            $path = $database->path($seed).'.report.json';
            $previous = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (($previous['configuration']['seed'] ?? null) !== $seed) throw new \RuntimeException('Report ownership mismatch.');
            $s = new SyntheticCompanyScenario;
            $s->config = array_replace(config('synthetic'), $previous['configuration']);
            $s->start = CarbonImmutable::parse($s->config['end_date'])->subDays($s->config['days'] - 1);
            $s->owner = User::where('email', $s->config['email'])->firstOrFail();
            $s->manager = User::where('email', 'manager@aims-demo.test')->firstOrFail();
            Auth::setUser($s->owner);
            $s->notes = $previous['scenario_notes'];
            $s->products = Product::orderBy('sku')->get()->map(fn ($p) => ['model' => $p])->all();
            $s->customers = Customer::orderBy('id')->get()->map(fn ($c) => ['model' => $c])->all();
            $s->suppliers = Supplier::orderBy('id')->get()->all();
            $s->warehouses = Warehouse::where('is_active', true)->orderBy('id')->get()->all();
            if ($this->option('repair-rounding')) {
                $accounting = app(AccountingService::class);
                $check = $accounting->reconciliation(false);
                $inventory = $check['rows']['inventory'];
                $difference = \App\Support\Money::minor($inventory['difference']);
                if ($difference !== 0) {
                    if (abs($difference) > 100 || collect($check['rows'])->except('inventory')->contains(fn ($r) => ! $r['reconciled'])) throw new \RuntimeException('Refusing a non-rounding or broader financial mismatch.');
                    foreach ($s->products as $row) if (app(InventoryIntegrityService::class)->productSnapshot($row['model'])['issues']) throw new \RuntimeException('Refusing correction while inventory quantities are inconsistent.');
                    $amount = \App\Support\Money::decimal(abs($difference));
                    $entry = $accounting->postMapped('synthetic_validation', 'company', $s->owner->company_id, 'pm3-rounding:'.$seed, today()->toDateString(), 'PM3 accumulated fractional valuation rounding carry; historical journals retained', [
                        ['mapping' => 'inventory', $difference > 0 ? 'debit' : 'credit' => $amount],
                        ['mapping' => 'inventory_adjustments', $difference > 0 ? 'credit' : 'debit' => $amount],
                    ]);
                    $s->notes['audited_rounding_correction'] = ['journal_id' => $entry->id, 'amount' => $amount, 'date' => today()->toDateString(), 'historical_journals_rewritten' => false];
                }
            }
            if ($entry = JournalEntry::where('source_key', 'pm3-rounding:'.$seed)->first()) $s->notes['audited_rounding_correction'] = ['journal_id' => $entry->id, 'amount' => $entry->total_debit, 'date' => $entry->posting_date->toDateString(), 'historical_journals_rewritten' => false];
            if ($this->option('refresh-advisory')) {
                $planning = app(\App\Services\InventoryPlanningService::class);
                $cases = [];
                foreach (array_slice($s->products, 0, 8) as $row) {
                    $planning->save($row['model']->id);
                    $planning->save($row['model']->id, ['warehouse_id' => $s->warehouses[0]->id]);
                    $cases[] = $planning->view($row['model']->id, ['warehouse_id' => $s->warehouses[0]->id]);
                }
                $s->notes['current_planning_after_bucket_fix'] = $cases;
                foreach ($s->products as $row) app(\App\Services\EnterpriseDecisionService::class)->refresh($row['model']->id);
                $optimizer = app(\App\Services\SupplyOptimizerService::class);
                foreach ($s->config['optimizer_budgets'] as $budget) {
                    $plan = $optimizer->submit(['horizon' => 30, 'product_ids' => array_map(fn ($row) => $row['model']->id, array_slice($s->products, 0, 8)), 'warehouse_ids' => [$s->warehouses[0]->id, $s->warehouses[1]->id], 'commitment_limit' => $budget, 'allow_transfers' => true, 'protect_critical' => true, 'transfer_lead_days' => 1]);
                    $optimizer->run($plan['id']);
                    $s->notes['current_day_optimizer'][$budget] = $optimizer->get($plan['id']);
                }
                app(SyntheticValidation::class)->currentDayQualifiedScenarios($s);
            }
            $report = app(SyntheticValidation::class)->report($s, false);
            $report['duration_seconds'] = $previous['duration_seconds'];
            $report['revalidated_at'] = now()->toIso8601String();
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info('Synthetic report revalidated without regenerating history.');
            return $report['integrity']['inventory_verified'] && $report['integrity']['financial_verified'] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) { $this->error($e::class.': '.$e->getMessage()); return self::FAILURE; }
    }
}
