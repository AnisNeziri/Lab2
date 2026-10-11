<?php

namespace Tests\Feature;

use App\Models\CustomerSalesSnapshot;
use App\Models\FinancialIntelligenceSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntelligencePagePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_overview_keeps_exact_headlines_and_pages_complete_evidence_on_demand(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        $rows = [];
        for ($i = 0; $i < 130; $i++) {
            $rows[] = ['key' => 'debt-'.$i, 'amount' => (string) $i, 'customer' => 'Buyer '.$i];
        }
        $headline = ['opening_recorded_cash' => '1000.10', 'inflows' => '200.20', 'outflows' => '300.30', 'expected_closing_cash' => '900.00', 'pressure' => [], 'timeline' => array_fill(0, 100, ['events' => $rows])];
        FinancialIntelligenceSnapshot::create(['version' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'as_of' => today(), 'evidence_cutoff' => now(), 'evidence' => ['base_currency' => 'EUR', 'confidence' => 'limited', 'cash' => ['complete' => false, 'accounts' => []], 'receivables' => $rows, 'customers' => [], 'inventory' => ['total' => '765.43', 'currency' => 'EUR', 'products' => []], 'totals' => ['EUR' => ['recorded_receivables' => '8385.00']]], 'forecast' => [30 => ['currencies' => ['EUR' => $headline]]]]);
        $full = $this->getJson('/api/financial-intelligence')->assertOk()->json();
        $overview = $this->getJson('/api/financial-intelligence?view=overview')->assertOk();
        $this->assertSame($full['forecast']['currencies']['EUR']['expected_closing_cash'], $overview->json('forecast.currencies.EUR.expected_closing_cash'));
        $this->assertSame($full['evidence']['totals'], $overview->json('evidence.totals'));
        $overview->assertJsonMissingPath('evidence.receivables')->assertJsonMissingPath('forecast.currencies.EUR.timeline');
        $this->assertLessThan(10000, strlen($overview->getContent()));
        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            $r = $this->getJson('/api/financial-intelligence?view=receivables&page='.$page)->assertOk()->assertJsonPath('pagination.receivables.total', 130);
            $seen = array_merge($seen, $r->json('evidence.receivables'));
        }
        $this->assertSame($rows, $seen);
        $this->getJson('/api/financial-intelligence?view=inventory')->assertOk()->assertJsonCount(0, 'evidence.landed_costs');
        $this->getJson('/api/financial-intelligence?view=receivables&per_page=9999')->assertUnprocessable();
        $this->getJson('/api/financial-intelligence?view=invalid')->assertUnprocessable();
    }

    public function test_customer_overview_counts_all_profiles_and_search_filters_before_pagination(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        $profiles = [];
        for ($i = 0; $i < 130; $i++) {
            $profiles[] = ['id' => $i + 1, 'name' => 'Buyer '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'purchase_count' => 2, 'state' => $i % 2 ? 'ACTIVE' : 'INACTIVE', 'summary' => ['revenue' => (string) (100 + $i), 'gross_profit' => '10', 'gross_margin' => 10, 'cost_coverage_percent' => 100], 'financial_context' => ['state' => 'available'], 'history' => array_fill(0, 50, ['test' => 'frozen long history'])];
        }
        CustomerSalesSnapshot::create(['version' => (string) Str::uuid(), 'fingerprint' => str_repeat('b', 64), 'as_of' => today(), 'evidence_cutoff' => now(), 'evidence' => ['profiles' => $profiles, 'opportunities' => [], 'canonical_baskets' => 260, 'currency' => 'EUR', 'history_from' => today()->subYear()->toDateString(), 'resource_trends' => []]]);
        $r = $this->getJson('/api/customer-sales-intelligence?view=overview')->assertOk()->assertJsonCount(0, 'evidence.profiles')->assertJsonPath('totals.customers_with_history', 130)->assertJsonPath('totals.inactive_customers', 65);
        $this->assertLessThan(10000, strlen($r->getContent()));
        $this->getJson('/api/customer-sales-intelligence?view=overview&q=&sort=value&page=1')->assertOk()->assertJsonPath('totals.customers_with_history', 130);
        $this->getJson('/api/customer-sales-intelligence?view=trends&page=2&sort=name')->assertOk()->assertJsonCount(50, 'evidence.profiles')->assertJsonPath('pagination.customers.total', 130)->assertJsonPath('evidence.profiles.0.name', 'Buyer 050');
        $this->getJson('/api/customer-sales-intelligence?view=trends&q=Buyer%20129')->assertOk()->assertJsonCount(1, 'evidence.profiles')->assertJsonPath('pagination.customers.total', 1);
        $this->getJson('/api/customer-sales-intelligence?view=atRisk')->assertOk()->assertJsonPath('pagination.customers.total',65);
    }
}
