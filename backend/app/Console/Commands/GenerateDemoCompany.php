<?php

namespace App\Console\Commands;

use App\Services\Synthetic\{SyntheticCompanyScenario, SyntheticDatabase};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Artisan, DB};

class GenerateDemoCompany extends Command
{
    protected $signature = 'aims:generate-demo-company {--seed=} {--days=} {--products=} {--customers=} {--suppliers=} {--warehouses=} {--intensity=} {--reset : Reset ONLY the owned synthetic database for this seed} {--yes : Confirm synthetic-only generation}';
    protected $description = 'Generate chronological PM3 history in a separate, guarded synthetic SQLite database.';

    public function handle(SyntheticDatabase $database, SyntheticCompanyScenario $scenario): int
    {
        $settings = config('synthetic');
        foreach (['seed', 'days', 'products', 'customers', 'suppliers', 'warehouses'] as $key) {
            if ($this->option($key) !== null) $settings[$key] = filter_var($this->option($key), FILTER_VALIDATE_INT);
        }
        if ($this->option('intensity') !== null) $settings['order_intensity'] = filter_var($this->option('intensity'), FILTER_VALIDATE_INT);
        validator($settings, ['seed' => 'required|integer|min:1|max:2147483647', 'days' => 'integer|min:7|max:180', 'products' => 'integer|min:9|max:120', 'customers' => 'integer|min:8|max:100', 'suppliers' => 'integer|min:4|max:6', 'warehouses' => 'integer|min:2|max:3', 'order_intensity' => 'integer|min:1|max:12'])->validate();
        $this->line('SYNTHETIC / TEST DATA ONLY: '.$database->path($settings['seed']));
        if (! $this->option('yes') && ! $this->confirm('Generate this isolated demo (never the configured company database)?')) return self::FAILURE;
        $original = config('database');
        try {
            $path = $database->prepare($settings['seed'], (bool) $this->option('reset'));
            Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
            // Migrations may provision a platform recovery identity. It must not be a usable demo login.
            DB::table('users')->where('role', 'superadmin')->update(['is_active' => false, 'api_token' => null]);
            DB::table('companies')->update(['name' => 'Synthetic platform recovery — TEST ONLY']);
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\RolePermissionSeeder', '--force' => true]);
            $report = $scenario->generate($settings, fn ($message) => $this->line($message));
            DB::table('_aims_synthetic_manifest')->update(['status' => 'complete']);
            file_put_contents($path.'.report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info('Synthetic generation complete: '.$path.'.report.json');
            $this->line('Login: '.$settings['email'].' / '.$settings['password']);
            return ($report['integrity']['inventory_verified'] && $report['integrity']['financial_verified']) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());
            if ($e instanceof \Illuminate\Validation\ValidationException) $this->line(json_encode($e->errors()));
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        } finally {
            \Illuminate\Support\Carbon::setTestNow(); \Carbon\CarbonImmutable::setTestNow();
            \Illuminate\Support\Facades\Date::setTestNow();
            \Illuminate\Support\Facades\Auth::forgetUser();
            DB::purge('sqlite'); config(['database' => $original]);
        }
    }
}
