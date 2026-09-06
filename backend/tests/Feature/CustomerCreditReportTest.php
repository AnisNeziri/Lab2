<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerCreditReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_report_uses_credit_service_values_and_company_totals(): void
    {
        $this->actingAsApiUser('admin');
        [$blocked, $overLimit, $normal] = $this->seedReportCustomers();
        $foreignCompany = Company::factory()->create();
        $foreignCustomerId = DB::table('customers')->insertGetId([
            'company_id' => $foreignCompany->id,
            'name' => 'Foreign Receivable',
            'current_debt' => '999.00',
            'current_credit' => '0.00',
            'credit_limit' => '100.00',
            'credit_status' => 'blocked',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('customer_debt_transactions')->insert([
            'company_id' => $foreignCompany->id,
            'customer_id' => $foreignCustomerId,
            'type' => 'debt_added',
            'source' => 'manual',
            'amount' => '999.00',
            'balance_before' => '0.00',
            'balance_after' => '999.00',
            'credit_before' => '0.00',
            'credit_after' => '0.00',
            'transaction_date' => now('Europe/Tirane')->subDays(101)->toDateString(),
            'due_date' => now('Europe/Tirane')->subDays(100)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/customers/credit-report?per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('summary.total_receivables', '370.00')
            ->assertJsonPath('summary.total_overdue', '320.00')
            ->assertJsonPath('summary.total_90_plus', '200.00')
            ->assertJsonPath('summary.total_advances', '70.00')
            ->assertJsonPath('data.0.customer_id', $blocked->id)
            ->assertJsonPath('data.0.status', 'BLOCKED')
            ->assertJsonPath('data.0.exposure', '150.00')
            ->assertJsonPath('data.0.overdue_90_plus', '200.00')
            ->assertJsonPath('data.1.customer_id', $overLimit->id)
            ->assertJsonPath('data.1.status', 'OVER LIMIT')
            ->assertJsonPath('data.2.customer_id', $normal->id)
            ->assertJsonPath('data.2.status', 'NORMAL')
            ->assertJsonMissing(['customer' => 'Foreign Receivable']);

        $this->assertSame(
            now('Europe/Tirane')->subDays(100)->toDateString(),
            $response->json('data.0.oldest_overdue_date')
        );
    }

    public function test_credit_report_filters_and_sorts_computed_credit_values(): void
    {
        $this->actingAsApiUser('admin');
        [$blocked, $overLimit, $normal] = $this->seedReportCustomers();

        $this->getJson('/api/customers/credit-report?filter=blocked')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.customer_id', $blocked->id);

        $this->getJson('/api/customers/credit-report?filter=over_limit&sort=exposure_asc')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.customer_id', $overLimit->id)
            ->assertJsonPath('data.1.customer_id', $blocked->id);

        $this->getJson('/api/customers/credit-report?filter=overdue&sort=overdue_asc')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.customer_id', $overLimit->id)
            ->assertJsonPath('data.1.customer_id', $blocked->id);

        $this->getJson('/api/customers/credit-report?filter=90_plus')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.customer_id', $blocked->id);

        $this->getJson('/api/customers/credit-report?sort=utilization_desc')
            ->assertOk()
            ->assertJsonPath('data.0.customer_id', $blocked->id)
            ->assertJsonPath('data.1.customer_id', $overLimit->id)
            ->assertJsonPath('data.2.customer_id', $normal->id);

        $this->getJson('/api/customers/credit-report?blocked=0')
            ->assertOk()
            ->assertJsonPath('total', 3);
    }

    private function seedReportCustomers(): array
    {
        $blocked = Customer::create($this->tenantAttributes([
            'name' => 'Blocked Corp',
            'current_debt' => '200.00',
            'current_credit' => '50.00',
            'credit_limit' => '100.00',
            'credit_status' => 'blocked',
        ]));
        $this->debt($blocked, '200.00', 100);

        $overLimit = Customer::create($this->tenantAttributes([
            'name' => 'Over Limit Corp',
            'current_debt' => '120.00',
            'current_credit' => '0.00',
            'credit_limit' => '100.00',
            'credit_status' => 'normal',
        ]));
        $this->debt($overLimit, '120.00', 45);

        $normal = Customer::create($this->tenantAttributes([
            'name' => 'Normal Corp',
            'current_debt' => '50.00',
            'current_credit' => '20.00',
            'credit_limit' => '100.00',
            'credit_status' => 'normal',
        ]));
        $this->debt($normal, '50.00', -10);

        return [$blocked, $overLimit, $normal];
    }

    private function debt(Customer $customer, string $amount, int $daysOverdue): void
    {
        $dueDate = now('Europe/Tirane')->subDays($daysOverdue);
        $customer->debtTransactions()->create([
            'company_id' => $this->apiCompany->id,
            'type' => 'debt_added',
            'source' => 'manual',
            'amount' => $amount,
            'balance_before' => '0.00',
            'balance_after' => $amount,
            'credit_before' => '0.00',
            'credit_after' => '0.00',
            'transaction_date' => $dueDate->copy()->subDay()->toDateString(),
            'due_date' => $dueDate->toDateString(),
        ]);
    }
}
