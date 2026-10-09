<?php

namespace App\Services\Synthetic;

use App\Models\{Category, Company, Customer, Product, Supplier, User, Warehouse, WarehouseStock};
use App\Services\{AccountingService, AnalyticsDataService, AutomationService, DailySaleService, FinancialAccountService, IntelligenceObservationService, PermissionService, SupplierCatalogueService, WarehouseOperationsService};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Hash};
use RuntimeException;

class SyntheticCompanyScenario
{
    public array $config;
    public array $products = [];
    public array $customers = [];
    public array $suppliers = [];
    public array $warehouses = [];
    public array $bins = [];
    public array $notes = [];
    public User $owner;
    public User $manager;
    public int $accountId;
    public CarbonImmutable $start;
    private string $token;
    private int $sequence = 0;

    public function generate(array $config, callable $progress): array
    {
        $this->config = $config;
        $this->start = CarbonImmutable::parse($config['end_date'])->startOfDay()->subDays($config['days'] - 1);
        $started = microtime(true);
        $this->clock(0, '07:00');
        $this->masters();
        $operations = app(SyntheticOperations::class);
        for ($day = 0; $day < $config['days']; $day++) {
            DB::transaction(function () use ($operations, $day, $config) {
            $this->clock($day, '08:00');
            if ($day === min($config['cold_start_day'] ?? 60, $config['days'] - 1)) $this->createProducts(true);
            $operations->morning($this, $day);
            $this->clock($day, '11:00');
            $operations->sales($this, $day);
            $this->clock($day, '16:00');
            $operations->payments($this, $day);
            if ($day % 21 === 14) $operations->count($this, $day);
            $this->clock($day, '23:40');
            app(DailySaleService::class)->updateDayNotes(today()->toDateString(), 'SYNTHETIC daily book: '.(today()->dayOfWeekIso === 7 ? 'closed Sunday; no fabricated zero-demand label' : 'warehouse operations and recorded dispatches'));
            if (\App\Models\DailySale::whereDate('sale_date', today())->exists()) app(DailySaleService::class)->finalizeDay(today()->toDateString());
            app(\App\Services\AutomationScheduler::class)->tick();
            app(AnalyticsDataService::class)->capture();
            if ($day > 0) foreach ($this->products as $row) {
                app(IntelligenceObservationService::class)->collectProduct($row['model']->fresh(), today()->subDay()->toDateString(), today()->subDay()->toDateString());
            }
            app(SyntheticIntelligence::class)->day($this, $day);
            }); // One chronological day, including frozen evidence; normal after-commit reactions run here.
            if (($day + 1) % 5 === 0 || $day === $config['days'] - 1) $progress('Chronological day '.($day + 1).'/'.$config['days'].' — '.today()->toDateString());
        }
        // Final completed-day observations are collected the following morning, never in advance.
        $this->clock($config['days'], '00:05');
        foreach ($this->products as $row) app(IntelligenceObservationService::class)->collectProduct($row['model']->fresh(), today()->subDay()->toDateString(), today()->subDay()->toDateString());
        app(SyntheticIntelligence::class)->finish($this);
        $report = app(SyntheticValidation::class)->report($this);
        $report['duration_seconds'] = round(microtime(true) - $started, 2);
        return $report;
    }

    public function clock(int $day, string $time): void
    {
        $date = $this->start->addDays($day)->setTimeFromTimeString($time);
        \Illuminate\Support\Carbon::setTestNow($date); CarbonImmutable::setTestNow($date);
        \Illuminate\Support\Facades\Date::setTestNow($date);
        if (isset($this->owner)) Auth::setUser($this->owner);
    }

    /** Seeded hashes avoid depending on global RNG calls made by unrelated services. */
    public function sample(string $key, int $min, int $max): int
    {
        return $min + hexdec(substr(hash('sha256', $this->config['seed'].':'.$key), 0, 7)) % ($max - $min + 1);
    }

    public function key(string $purpose): string
    {
        $hash = hash('sha256', $this->config['seed'].':'.$purpose.':'.(++$this->sequence));
        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }

    public function api(string $method, string $path, array $data = []): array
    {
        $request = Request::create('/api/'.$path, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], json_encode($data));
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $body = json_decode($response->getContent(), true) ?? [];
        $kernel->terminate($request, $response);
        Auth::setUser($this->owner);
        if ($response->getStatusCode() >= 400) throw new RuntimeException($method.' '.$path.' returned '.$response->getStatusCode().': '.json_encode($body));
        return $body;
    }

    private function masters(): void
    {
        $company = Company::create(['name' => $this->config['company'], 'address' => 'Synthetic warehouse district, Prishtina', 'base_currency' => 'EUR', 'accounting_start_date' => today()->toDateString()]);
        // Reassign only the empty recovery fixture inside this verified synthetic database.
        DB::table('warehouses')->whereNotIn('id', DB::table('warehouse_stock')->select('warehouse_id'))->update(['company_id' => $company->id, 'name' => 'Synthetic initial warehouse (inactive)', 'is_active' => false, 'is_default' => false]);
        $this->token = 'synthetic-'.$this->config['seed'];
        $this->owner = User::create(['company_id' => $company->id, 'name' => 'Synthetic Owner', 'email' => $this->config['email'], 'password' => Hash::make($this->config['password']), 'role' => 'admin', 'is_active' => true, 'api_token' => hash('sha256', $this->token), 'preferences' => ['language' => 'en', 'theme' => 'light']]);
        $this->manager = User::create(['company_id' => $company->id, 'name' => 'Synthetic Warehouse Manager', 'email' => 'manager@aims-demo.test', 'password' => Hash::make($this->config['password']), 'role' => 'manager', 'is_active' => true]);
        foreach ([$this->owner, $this->manager] as $user) $user->forceFill(['email_verified_at' => now()])->save();
        Auth::setUser($this->owner);
        $this->api('GET', 'me'); // Install the same lazy module permissions as a real signed-in owner.
        app(AccountingService::class)->initialize();
        $account = app(FinancialAccountService::class)->createAccount(['name' => 'Synthetic EUR Operating Bank', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => $this->config['opening_cash'], 'opening_date' => today()->toDateString(), 'is_active' => true]);
        $this->accountId = $account->id;
        for ($i = 0; $i < $this->config['warehouses']; $i++) {
            $w = app(WarehouseOperationsService::class)->createWarehouse(['name' => ['Prishtina Dispatch', 'Ferizaj Reserve', 'Prizren Trade Counter'][$i], 'code' => 'SYN-W'.($i + 1), 'address' => 'SYNTHETIC location '.($i + 1), 'is_default' => $i === 0, 'is_active' => true]);
            $this->warehouses[] = $w;
            for ($j = 0; $j < 3; $j++) $this->bins[$w->id][] = app(WarehouseOperationsService::class)->createLocation(['warehouse_id' => $w->id, 'type' => 'zone', 'name' => ['Fabric floor', 'Hardware racks', 'Receiving and inspection'][$j], 'code' => 'Z'.($j + 1), 'floor_level' => 1, 'is_active' => true]);
        }
        $names = ['Balkan Materials Cooperative', 'Adriatic Technical Textiles', 'Anatolia Wholesale Supply', 'Dardania Express Components', 'Central Europe Materials', 'Regional Reserve Supply'];
        for ($i = 0; $i < $this->config['suppliers']; $i++) $this->suppliers[] = Supplier::create(['name' => $names[$i].' [TEST]', 'email' => 'supplier'.($i + 1).'@aims-demo.test', 'phone' => '+000000'.($i + 1), 'address' => 'Synthetic supplier address', 'is_active' => true]);
        $segments = ['frequent', 'regular', 'occasional', 'growth', 'decline', 'inactive', 'new', 'concentration'];
        for ($i = 0; $i < $this->config['customers']; $i++) {
            $segment = $segments[$i % count($segments)];
            $this->customers[] = ['model' => Customer::create(['name' => sprintf('%s Workshop %02d — synthetic furniture and upholstery trading', ucfirst($segment), $i + 1), 'business_name' => 'SYNTHETIC Workshop '.($i + 1), 'customer_type' => 'business', 'business_registration_number' => 'TEST-NUI-'.($i + 1), 'fiscal_number' => 'TEST-FISCAL-'.($i + 1), 'billing_address' => 'Synthetic trading street '.($i + 1), 'municipality' => 'Prishtina', 'country_code' => 'XK', 'is_active' => true, 'email' => 'customer'.($i + 1).'@aims-demo.test', 'payment_terms_days' => [14, 21, 30][$i % 3], 'current_debt' => 0, 'current_credit' => 0, 'credit_status' => 'normal']), 'segment' => $segment, 'index' => $i];
        }
        $this->createProducts(false);
        app(\App\Services\InvoiceProfileService::class)->updateForCurrentCompany(['legal_name' => $this->config['company'], 'business_registration_number' => 'TEST-NUI-SELLER', 'fiscal_number' => 'TEST-FISCAL-SELLER', 'registered_address' => 'Synthetic warehouse district', 'municipality' => 'Prishtina', 'country_code' => 'XK', 'invoice_prefix' => 'TEST-INV', 'credit_note_prefix' => 'TEST-CN', 'is_vat_registered' => false, 'default_payment_terms_days' => 14, 'default_language' => 'en']);
        $this->documents();
        $rule = app(AutomationService::class)->save(['name' => 'Synthetic low-stock review', 'trigger' => 'inventory.low_stock', 'priority' => 'high', 'conditions' => ['all' => []], 'actions' => [['type' => 'create_task', 'title' => 'Review low stock and available transfers', 'due_days' => 1], ['type' => 'notify', 'title' => 'Synthetic low stock review']]]);
        app(AutomationService::class)->toggle($rule, true);
    }

    private function createProducts(bool $cold): void
    {
        $categories = ['Upholstery Fabrics', 'Lining and Interfacing', 'Furniture Hardware', 'Foam and Accessories', 'Packing Materials'];
        $names = ['Milano', 'Lining Cotton', 'Door Handles', 'Foam Cushion', 'Hardware Carton', 'Staple Wire', 'Decorative Trim', 'Velvet Premium', 'New Technical Textile'];
        $units = ['m', 'm', 'pcs', 'pcs', 'boxes', 'kg', 'rolls', 'm', 'm'];
        for ($i = 0; $i < $this->config['products']; $i++) {
            $profile = $this->config['profiles'][$i % 9];
            if (($profile === 'cold_start') !== $cold) continue;
            $base = [14, 8, 25, 6, 2, 80, 1, 9, 5][$i % 9];
            $cost = [4.5, 2.1, 1.3, 18, 22, 3, 115, 12, 7][$i % 9];
            $supplier = $this->suppliers[$i % count($this->suppliers)];
            $cat = Category::firstOrCreate(['name' => $categories[$i % 5]]);
            $data = ['name' => $names[$i % 9].' '.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 'sku' => 'SYN-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), 'barcode' => '990'.str_pad((string) ($i + 1), 10, '0', STR_PAD_LEFT), 'category_id' => $cat->id, 'supplier_id' => $supplier->id, 'unit' => $units[$i % 9], 'quantity' => $base * 24, 'min_quantity' => $base * 8, 'high_stock_threshold' => $base * 55, 'safety_stock' => $base * 5, 'reorder_point' => $base * 15, 'purchase_price' => $cost, 'selling_price' => round($cost * 1.45, 2), 'price' => round($cost * 1.45, 2), 'description' => 'SYNTHETIC / TEST DATA. Demand profile: '.$profile.'.', 'weight_kg' => .4, 'volume_m3' => .01, 'country_of_origin' => 'TR'];
            if ($data['unit'] === 'pcs') $data['unit_conversions'] = [['code' => 'box', 'label' => 'Fixed pack of 10', 'conversion_mode' => 'fixed', 'factor_to_base' => 10, 'is_active' => true]];
            $created = $this->api('POST', 'products', $data);
            $p = Product::findOrFail($created['id']);
            $this->products[] = ['model' => $p, 'profile' => $profile, 'base' => $base, 'cost' => $cost, 'index' => $i];
            // Explicit owner policy: reserve holds 20% of future demand; dispatch serves 80%.
            foreach (array_slice($this->warehouses, 0, 2) as $wIndex => $warehouse) app(\App\Services\InventoryPlanningService::class)->policy($p->id, ['warehouse_id' => $warehouse->id, 'service_level' => .95, 'priority' => $profile === 'volume' ? 5 : 3, 'review_days' => 7, 'allocation_share' => $wIndex === 0 ? .8 : .2]);
            foreach ($this->suppliers as $s => $vendor) {
                $offer = ['product_id' => $p->id, 'supplier_id' => $vendor->id, 'purchase_price' => round($cost * [1, 1.08, .97, 1.18, 1.1, 1.05][$s], 2), 'currency' => 'EUR', 'pack_size' => $p->unit === 'pcs' ? 10 : 1, 'minimum_order_quantity' => $p->unit === 'pcs' ? 20 : 5, 'usual_lead_time_days' => [7, 5, 12, 3, 8, 6][$s], 'is_preferred' => $vendor->id === $supplier->id, 'price_change_reason' => 'Synthetic initial supplier offer'];
                $existing = $p->supplierCatalogue()->where('supplier_id', $vendor->id)->first();
                $catalogue = app(SupplierCatalogueService::class);
                $existing ? $catalogue->update($existing, $offer) : $catalogue->create($offer);
            }
        }
    }

    private function documents(): void
    {
        $service = app(\App\Services\DocumentService::class);
        $type = $service->types()->firstWhere('name', 'Product Specification');
        foreach (array_slice($this->products, 0, 3) as $row) {
            $p = $row['model'];
            $temp = tempnam(storage_path('app/synthetic'), 'pm3-doc-');
            try {
                file_put_contents($temp, "SYNTHETIC / TEST DATA ONLY\nProduct specification: {$p->name}\nSKU: {$p->sku}\nInventory unit: {$p->unit}\nGenerated safe placeholder. Not a real certificate, tax document or private company document.\n");
                $service->upload(new \Illuminate\Http\UploadedFile($temp, $p->sku.'-specification.txt', 'text/plain', null, true), ['document_type_id' => $type->id, 'title' => 'Synthetic specification '.$p->name, 'reference' => 'SYN-SPEC-'.$p->id, 'description' => 'Safe generated PM3 document', 'confidentiality' => 'internal', 'document_date' => today()->toDateString(), 'entity_type' => 'product', 'entity_id' => $p->id]);
            } finally { if (is_file($temp)) unlink($temp); }
        }
    }
}
