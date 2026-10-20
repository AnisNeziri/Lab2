<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AccountingPostingMapping;
use App\Models\AccountingRecoveryAttempt;
use App\Models\ActivityLog;
use App\Models\BankStatement;
use App\Models\BankStatementRow;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\FinancialIntelligenceSnapshot;
use App\Models\JournalEntry;
use App\Models\LandedCost;
use App\Models\OnboardingSession;
use App\Models\PurchaseOrderPayment;
use App\Support\CompanyClock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/** One workspace projection; no financial position is stored or posted here. */
class FinancialControlService
{
    public function __construct(private readonly AccountingService $accounting, private readonly FinancialIntegrityChecks $integrity) {}

    public function summary(?string $from = null, ?string $to = null): array
    {
        $to ??= CompanyClock::today()->toDateString();
        $from ??= CompanyClock::today()->startOfMonth()->toDateString();
        $tb = $this->accounting->trialBalance($from, $to);
        $rows = collect($tb['rows']);
        $mappings = AccountingPostingMapping::query()->pluck('accounting_account_id', 'mapping_key');
        $value = function (string $key, bool $credit = false) use ($rows, $mappings) {
            $root = $mappings->get($key);
            if (! $root) {
                return null;
            }
            $ids = [$root];
            do {
                $before = count($ids);
                $ids = array_values(array_unique([...$ids, ...$rows->whereIn('parent_id', $ids)->pluck('id')->all()]));
            } while (count($ids) !== $before);
            $amount = $rows->whereIn('id', $ids)->sum(fn ($r) => Money::minor($r['closing_balance']));

            return Money::decimal($credit ? -$amount : $amount);
        };
        $pl = $this->accounting->profitAndLoss($from, $to, $tb);
        $summary = ['cash' => $value('cash'), 'bank' => $value('bank'), 'accounts_receivable' => $value('accounts_receivable'),
            'customer_advances' => $value('customer_advances', true), 'accounts_payable' => $value('accounts_payable', true),
            'supplier_advances' => $value('supplier_advances'), 'inventory_value' => $value('inventory'),
            'revenue' => $pl['revenue'], 'expenses' => $pl['operating_expenses'], 'gross_profit' => $pl['gross_profit'], 'net_profit' => $pl['net_profit']];
        $counts = $this->counts();
        $reconciliation = $this->accounting->reconciliation(false);
        $integrity = $this->integrity->checks();
        $attention = [];
        foreach ($reconciliation['rows'] as $row) {
            if (! $row['reconciled']) {
                $attention[] = ['code' => 'control_'.$row['control'], 'severity' => 'critical', 'count' => 1,
                    'label' => ucwords(str_replace('_', ' ', $row['control'])).' differs from the General Ledger', 'difference' => $row['difference'], 'url' => '/accounting?tab=reconciliation'];
            }
        }
        foreach ($integrity as $check) {
            if (! $check['passed']) {
                $attention[] = $check;
            }
        }
        $overdue = app(ReceivableReports::class)->positions(CompanyClock::today()->toDateString())->whereNotNull('due_date')->whereDate('due_date', '<', CompanyClock::today()->toDateString())->where('currency', $tb['currency']);
        $overdueSummary = (clone $overdue)->selectRaw('COUNT(*) count,COALESCE(SUM(outstanding),0) amount')->first();
        if ($overdueSummary->count) {
            $attention[] = ['code' => 'overdue_receivables', 'severity' => 'attention', 'count' => (int) $overdueSummary->count, 'label' => 'Overdue customer receivables require collection review', 'amount' => Money::normalize($overdueSummary->amount), 'url' => '/accounting?tab=ar'];
        }
        foreach ([['unbalanced_drafts', 'Unbalanced draft journals', 'journal'], ['stale_drafts', 'Draft journals older than seven days', 'journal'],
            ['unreconciled_bank_items', 'Unreconciled bank items', 'bank'], ['unreconciled_system_bank_items', 'Bank activity is awaiting a statement match', 'bank'], ['unreviewed_supplier_bills', 'Supplier invoices need review', 'ap'],
            ['unposted_financial_documents', 'Unposted financial documents', 'ap'], ['pending_landed_costs', 'Landed cost is awaiting posting', 'landed'],
            ['opening_issues', 'Opening reconciliation is unfinished', 'opening'], ['failed_financial_jobs', 'Financial posting needs recovery', 'integrity'],
            ['closed_period_attempts', 'Recent posting attempts were blocked by a closed period', 'periods']] as [$key,$label,$tab]) {
            if ($counts[$key]) {
                $attention[] = ['code' => $key, 'severity' => 'attention', 'count' => $counts[$key], 'label' => $label, 'url' => '/accounting?tab='.$tab];
            }
        }
        $cash = $summary['cash'] === null || $summary['bank'] === null ? null : Money::add($summary['cash'], $summary['bank']);
        $working = in_array(null, [$cash, $summary['accounts_receivable'], $summary['inventory_value'], $summary['accounts_payable']], true) ? null
            : Money::subtract(Money::add($cash, $summary['accounts_receivable'], $summary['inventory_value']), $summary['accounts_payable']);
        $vat=app(VatReportingService::class)->reconciliation(null,$to);
        $period = AccountingPeriod::query()->whereDate('starts_at', '<=', CompanyClock::today())->whereDate('ends_at', '>=', CompanyClock::today())->first();

        return ['currency' => $tb['currency'], 'from' => $from, 'to' => $to, 'summary' => $summary, 'counts' => $counts, 'current_period' => $period,
            'books_balanced' => $tb['balanced'], 'controls_reconciled' => $reconciliation['all_reconciled'],
            'integrity_status' => collect($attention)->contains(fn ($a) => $a['severity'] === 'critical') ? 'BLOCKED' : ($attention ? 'ATTENTION' : 'READY'),
            'attention' => array_slice($attention, 0, 25), 'reconciliation' => $reconciliation,
            'reconciliation_basis' => 'Current operational positions and current General Ledger. Dated statement cards above use the selected end date.',
            'working_capital' => ['cash_bank' => $cash, 'receivables' => $summary['accounts_receivable'], 'inventory' => $summary['inventory_value'],
                'payables' => $summary['accounts_payable'], 'total' => $working, 'customer_advances' => $summary['customer_advances'], 'supplier_advances' => $summary['supplier_advances'],
                'explanation' => 'Cash and bank + receivables + inventory − payables. Advances remain visible separately.'],
            'tax_controls' => ['input_vat' => $vat['controls']['input_vat']['gl_total'], 'output_vat' => $vat['controls']['output_vat']['gl_total'], 'reconciliation'=>$vat,'url'=>'/tax?tab=vat', 'qualification' => 'Configured posted tax-control balances; not an official tax filing.'],
            'cash_outlook' => $this->outlook($tb['currency']), 'posted_values_only' => true, 'checked_at' => now()->toIso8601String()];
    }

    public function counts(?string $to = null): array
    {
        $cutoff = $to ? CarbonImmutable::parse($to)->addDay()->toDateString() : null;
        $drafts = JournalEntry::query()->where('status', 'draft')->when($cutoff, fn ($q) => $q->where('posting_date', '<', $cutoff));

        return ['unbalanced_drafts' => $this->integrity->journalDifferences('draft', $to), 'draft_journals' => (clone $drafts)->count(),
            'stale_drafts' => (clone $drafts)->where('created_at', '<', now()->subDays(7))->count(),
            'unreconciled_bank_items' => BankStatementRow::query()->whereIn('status', ['unmatched', 'suggested'])->when($cutoff, fn ($q) => $q->where('transaction_date', '<', $cutoff))->count(),
            'unreconciled_system_bank_items' => app(BankReconciliationProof::class)->unreconciledSystem(null, null, $to)->count(),
            'uncompleted_bank_statements' => BankStatement::query()->where('reconciliation_status', '!=', 'completed')->when($cutoff, fn ($q) => $q->where('period_to', '<', $cutoff))->count(),
            'unreviewed_supplier_bills' => Expense::query()->where('document_type', 'purchase_invoice')->whereNotNull('supplier_id')->whereIn('status', ['draft', 'posted'])->whereNull('approved_at')->when($cutoff, fn ($q) => $q->where('invoice_date', '<', $cutoff))->count(),
            'unposted_financial_documents' => Expense::query()->where('status', 'draft')->when($cutoff, fn ($q) => $q->where('invoice_date', '<', $cutoff))->count(),
            'pending_landed_costs' => LandedCost::query()->where('status', 'draft')->when($cutoff, fn ($q) => $q->where('created_at', '<', $cutoff))->count(),
            'unallocated_customer_advances' => Customer::query()->where('current_credit', '>', 0)->count(),
            'unallocated_supplier_payments' => PurchaseOrderPayment::query()->where('status', 'completed')->whereRaw('amount > COALESCE((SELECT SUM(amount) FROM supplier_invoice_payment_allocations WHERE purchase_order_payment_id=purchase_order_payments.id),0)')->count(),
            'opening_issues' => OnboardingSession::query()->whereNotIn('status', ['locked', 'cancelled'])->count(),
            'closed_period_attempts' => ActivityLog::query()->where('action', 'accounting.closed_period_attempt')->where('created_at', '>=', now()->subDays(7))->count(),
            'failed_financial_jobs' => AccountingRecoveryAttempt::query()->where('status', 'failed')->whereHas('exception', fn ($q) => $q->where('status', 'open'))->count()];
    }

    public function readiness(AccountingPeriod $period): array
    {
        $to = $period->ends_at->toDateString();
        $counts = $this->counts($to);
        $tb = $this->accounting->trialBalance(null, $to);
        $bs = $this->accounting->balanceSheet($to);
        $reconciliation = app(DatedFinancialReconciliation::class)->report($to);
        $currentReconciliation = $this->accounting->reconciliation(false);
        $inventory = app(InventoryIntegrityService::class)->companySummary();
        $checks = $this->integrity->checks($to);
        $checks[] = $this->check('period_ended', 'The selected period has ended', $to <= CompanyClock::today()->toDateString() ? 0 : 1, 'periods');
        $checks[] = $this->check('trial_balance', 'Trial Balance is balanced', $tb['balanced'] ? 0 : 1, 'reports');
        $checks[] = $this->check('balance_sheet', 'Balance Sheet is balanced', $bs['balanced'] ? 0 : 1, 'reports');
        foreach ($reconciliation['rows'] as $row) {
            $checks[] = [...$this->check($row['control'], ucwords(str_replace('_', ' ', $row['control'])).' reconciles at period end', $row['reconciled'] ? 0 : 1, 'reconciliation'), 'difference' => $row['difference'], 'detail' => implode(' ', $row['evidence_issues'])];
        }
        foreach ($currentReconciliation['rows'] as $row) {
            if (! $row['reconciled']) {
                $checks[] = [...$this->check('current_'.$row['control'], ucwords(str_replace('_', ' ', $row['control'])).' currently differs from GL', 1, 'reconciliation'), 'difference' => $row['difference']];
            }
        }
        $checks[] = [...$this->check('inventory_integrity', 'Inventory quantities and trace states reconcile', $inventory['issue_count'], 'reconciliation'), 'examples' => $inventory['examples']];
        $invalidBankProofs = 0;
        $bankProofExamples = [];
        BankStatement::query()->where('reconciliation_status', 'completed')->whereDate('period_from', '<=', $to)->whereDate('period_to', '>=', $period->starts_at->toDateString())->chunkById(50, function ($statements) use (&$invalidBankProofs, &$bankProofExamples) {
            foreach ($statements as $statement) {
                $proof = app(BankReconciliationProof::class)->summary($statement);
                if (! $proof['ready_to_complete'] || ! $statement->reconciliation_proof || data_get($statement->reconciliation_proof, 'historical')) {
                    $invalidBankProofs++;
                    if (count($bankProofExamples) < 5) {
                        $bankProofExamples[] = ['id' => $statement->id, 'file_name' => $statement->file_name, 'failed_checks' => collect($proof['checks'])->where('passed', false)->pluck('label')->all()];
                    }
                }
            }
        });
        $checks[] = [...$this->check('bank_completion_proofs', 'Completed bank statements still have valid balance evidence', $invalidBankProofs, 'bank'), 'examples' => $bankProofExamples];
        foreach ([['draft_journals', 'No unposted journals', 'journal'], ['unbalanced_drafts', 'No unbalanced drafts', 'journal'],
            ['unreconciled_bank_items', 'All bank statement rows reviewed', 'bank'], ['unreconciled_system_bank_items', 'All bank activity has a statement match', 'bank'], ['uncompleted_bank_statements', 'Statement balances are reconciled', 'bank'],
            ['unreviewed_supplier_bills', 'Supplier invoices reviewed', 'ap'], ['unposted_financial_documents', 'No unposted financial documents', 'ap'],
            ['pending_landed_costs', 'No pending landed-cost postings', 'landed'], ['opening_issues', 'Opening reconciliation is complete', 'opening'],
            ['failed_financial_jobs', 'No unresolved failed financial postings', 'integrity']] as [$key,$label,$tab]) {
            $checks[] = $this->check($key, $label, $counts[$key], $tab);
        }
        // An advance can legitimately remain a balance at month end. It is
        // inspected and disclosed, never netted or forced into expense/revenue.
        $checks[] = $this->check('unapplied_customer_advances', 'Review unapplied customer advances', $counts['unallocated_customer_advances'], 'ar', false);
        foreach(app(\App\Services\Workforce\PayrollReadService::class)->closeChecks($period->starts_at->toDateString(),$to) as $payrollCheck) $checks[]=[...$this->check($payrollCheck['code'],$payrollCheck['label'],$payrollCheck['count'],'periods'),'url'=>'/payroll'];
        $checks[] = $this->check('unapplied_supplier_advances', 'Review unapplied supplier advances', $counts['unallocated_supplier_payments'], 'ap', false);
        $blocked = collect($checks)->contains(fn ($c) => $c['required'] && ! $c['passed']);
        $attention = collect($checks)->contains(fn ($c) => ! $c['passed']);

        return ['period' => $period, 'checked_at' => now()->toIso8601String(), 'status' => $blocked ? 'BLOCKED' : ($attention ? 'ATTENTION' : 'READY'),
            'ready_to_close' => ! $blocked, 'checks' => $checks, 'reconciliation' => $reconciliation, 'current_reconciliation' => $currentReconciliation, 'inventory' => $inventory,
            'retained_earnings_policy' => 'Current earnings are derived dynamically from posted revenue and expense journals across fiscal-year boundaries. No duplicate closing/opening profit journal is generated.',
            'basis' => 'Period-end financial statements and independently dated subledger/GL comparisons, plus current operational integrity checks. Missing historical evidence blocks closing.'];
    }

    private function check(string $code, string $label, int $count, string $tab, bool $required = true): array
    {
        return compact('code', 'label', 'count', 'required') + ['passed' => $count === 0, 'severity' => $required ? 'blocked' : 'attention', 'url' => '/accounting?tab='.$tab];
    }

    private function outlook(string $currency): ?array
    {
        foreach (['analytics.finance', 'finance.view', 'financial_accounts.view'] as $p) {
            if (! app(PermissionService::class)->roleHasPermission(Auth::user()->role, $p)) {
                return null;
            }
        }
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            return null;
        }
        // Select only six scalar forecast facts. Never hydrate evidence,
        // timelines, model history or the full frozen intelligence payload.
        $r = FinancialIntelligenceSnapshot::query()->select(['id', 'as_of', 'evidence_cutoff',
            'forecast->30->currencies->'.$currency.'->inflows as expected_inflows',
            'forecast->30->currencies->'.$currency.'->outflows as known_outflows',
            'forecast->30->currencies->'.$currency.'->expected_closing_cash as expected_closing_cash'])
            ->whereNull('evidence->archived')->latest('id')->first();
        if (! $r) {
            return ['state' => 'not_calculated', 'url' => '/financial-intelligence'];
        }

        return ['state' => 'ready', 'snapshot_id' => $r->id, 'as_of' => $r->as_of->toDateString(), 'currency' => $currency, 'horizon' => 30,
            'expected_collections' => $r->expected_inflows, 'scheduled_outflows' => $r->known_outflows, 'expected_closing_cash' => $r->expected_closing_cash,
            'stale' => $r->evidence_cutoff->lt(now()->subMinutes(config('financial_intelligence.refresh_minutes'))) || Cache::has('finance-dirty:'.Auth::user()->company_id),
            'booked_accounting' => false, 'qualification' => 'Advisory timing from Financial Intelligence, separate from posted books. Review known commitments and predicted collection timing.', 'url' => '/financial-intelligence'];
    }
}
