<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\AimsToolRegistry;
use App\Services\CustomerDebtService;
use App\Services\FinanceAssistantFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DC2FinanceAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_period_is_selected_for_an_undated_month_close_question(): void
    {
        $this->context();
        $today = \App\Support\CompanyClock::today();
        $period = \App\Models\AccountingPeriod::create($this->tenantAttributes([
            'name' => 'Current close review', 'starts_at' => $today->startOfMonth(),
            'ends_at' => $today->endOfMonth(), 'status' => 'open',
        ]));
        $this->postJson('/api/intelligence-assistant/ask', [
            'language' => 'sq', 'question' => 'Çfarë po e bllokon mbylljen e muajit?',
        ])->assertOk()->assertJsonPath('next_action.url', '/accounting?tab=periods&period='.$period->id)
            ->assertJsonPath('tools.0.tool', 'get_month_close_blockers');
    }

    private function context(): void
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        app(AccountingService::class)->initialize();
    }

    private function books(): array
    {
        return collect(['journal_entries', 'journal_lines', 'customer_debt_transactions', 'customer_settlement_allocations', 'expenses', 'expense_payments', 'purchase_order_payments', 'supplier_payment_allocation_requests', 'financial_accounts', 'financial_account_transactions', 'bank_statements', 'bank_statement_rows', 'bank_statement_matches', 'accounting_periods'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_authoritative_bilingual_and_typo_finance_answers_leave_all_books_unchanged(): void
    {
        $this->context();
        $c = Customer::create($this->tenantAttributes(['name' => 'DC2 debtor', 'current_debt' => 0, 'current_credit' => 0]));
        $debts = app(CustomerDebtService::class);
        $debts->addDebt($c, ['amount' => 3700, 'transaction_date' => '2026-09-01', 'due_date' => '2026-09-30', 'idempotency_key' => 'ask-sale']);
        $debts->recordPayment($c, ['amount' => 3400, 'transaction_date' => '2026-09-15', 'payment_method' => 'cash', 'idempotency_key' => 'ask-payment']);
        $before = $this->books();
        foreach (['how much customers owe us', 'sa borxh kane klientet', 'show recievables overdue invoices'] as $question) {
            $r = $this->postJson('/api/intelligence-assistant/ask', ['question' => $question])->assertOk()->assertJsonPath('intent', 'finance_receivables')->assertJsonPath('read_only', true)->assertJsonPath('tools.0.tool', 'get_accounting_receivables');
            $this->assertStringContainsString('300.00 EUR', $r->json('text'));
        }
        foreach (['how much cash do we have' => 'finance_cash', 'what do we owe suppliers' => 'finance_payables', 'what payments need alocation' => 'finance_allocation', 'what is blocking month close' => 'finance_close', "why doesn't inventory match accounting" => 'finance_reconciliation', 'why did profit drop this month' => 'finance_profit'] as $question => $intent) {
            $this->postJson('/api/intelligence-assistant/ask', ['question' => $question])->assertOk()->assertJsonPath('intent', $intent)->assertJsonPath('read_only', true);
        }
        $r = $this->postJson('/api/intelligence-assistant/ask', ['question' => 'how much customers owe us'])->assertOk()->json();
        $this->postJson('/api/intelligence-assistant/ask', ['question' => 'Show customers', 'conversation_id' => $r['conversation_id']])->assertOk()->assertJsonPath('intent', 'finance_receivables');
        $comparison = $this->postJson('/api/intelligence-assistant/ask', ['question' => 'Compare last month', 'conversation_id' => $r['conversation_id']])->assertOk()->assertJsonPath('intent', 'finance_receivables_compare')->json();
        $this->assertNotContains('get_posted_profit_comparison', array_column($comparison['tools'], 'tool'));
        $this->assertStringContainsString('Customers owe us', $comparison['text']);
        $this->assertSame($before, $this->books());
    }

    public function test_assistant_never_executes_financial_commands_and_tool_catalog_has_only_read_operations(): void
    {
        $this->context();
        $before = $this->books();
        foreach (['post journal', 'reverse journal', 'allocate payment', 'close period', 'reconcile bank', 'pay supplier'] as $question) {
            $r = $this->postJson('/api/intelligence-assistant/ask', ['question' => $question])->assertOk()->assertJsonPath('intent', 'finance_action_boundary')->assertJsonPath('read_only', true)->assertJsonPath('tools', []);
            $this->assertArrayNotHasKey('action', $r->json());
        }
        $catalog = collect(app(AimsToolRegistry::class)->catalog());
        foreach (FinanceAssistantFacts::TOOLS as $name) {
            $this->assertTrue($catalog->firstWhere('name', $name)['read_only']);
        }
        $this->assertSame($before, $this->books());
    }

    public function test_overdue_customer_guidance_excludes_larger_balances_that_are_not_due(): void
    {
        $this->context();
        $today = \App\Support\CompanyClock::today();
        $debts = app(CustomerDebtService::class);
        foreach ([['Late customer', 300, $today->subDay()], ['Current customer', 900, $today->addDay()]] as [$name, $amount, $due]) {
            $customer = Customer::create($this->tenantAttributes(['name' => $name, 'current_debt' => 0, 'current_credit' => 0]));
            $debts->addDebt($customer, ['amount' => $amount, 'transaction_date' => $today->subDays(2)->toDateString(), 'due_date' => $due->toDateString()]);
        }

        $reply = $this->postJson('/api/intelligence-assistant/ask', [
            'question' => 'Cilët klientë kanë borxhe të vonuara?',
            'language' => 'sq',
        ])->assertOk()->assertJsonPath('intent', 'finance_receivables')
            ->assertJsonCount(2, 'cards')
            ->assertJsonPath('cards.1.title', 'Late customer');

        $this->assertStringContainsString('300.00 EUR', $reply->json('text'));
    }

    public function test_finance_tools_enforce_permissions_even_when_a_tool_name_is_requested_directly(): void
    {
        $this->actingAsApiUser('staff');
        $this->postJson('/api/intelligence-assistant/ask', ['question' => 'how much cash do we have'])->assertForbidden();
        $this->postJson('/api/capabilities/get_accounting_control_summary/execute', ['input' => []])->assertForbidden();
        $this->assertDatabaseCount('journal_entries', 0);
    }
}
