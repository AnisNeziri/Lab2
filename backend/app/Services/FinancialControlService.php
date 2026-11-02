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
        $opening=OnboardingSession::query()->latest('id')->first(['id','status','approved_at','verified_at','verification']);
        $openingVerified=$opening?->status==='locked' && $opening->approved_at && $opening->verified_at && !empty($opening->verification['report']['ready_for_review']);
        if(!$openingVerified) $attention[]=['code'=>'opening_not_verified','severity'=>'attention','count'=>1,'label'=>'Opening balances have not been certified through company opening','url'=>'/onboarding'];
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

        return ['opening_readiness'=>['status'=>$openingVerified?'verified':'not_verified','session_id'=>$opening?->id,'url'=>'/onboarding'],'basis'=>'posted_books','currency' => $tb['currency'], 'from' => $from, 'to' => $to, 'summary' => $summary, 'counts' => $counts, 'current_period' => $period,
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

    /** Accountant review projection over the existing control and onboarding authorities. */
    public function openingReview(): array
    {
        $company=Auth::user()->company;
        $locked=OnboardingSession::query()->where('status','locked')->latest('id')->first();
        $sourceKeys=['accounts_receivable'=>'receivables','customer_advances'=>'customer_advances','accounts_payable'=>'payables','supplier_advances'=>'supplier_advances','inventory'=>'inventory_value','cash_bank'=>null];
        $reviews=\App\Models\AccountingException::query()->where('type','opening_review')->get()->keyBy('exception_key');
        $rows=[];$journalCount=JournalEntry::count();
        foreach($this->accounting->reconciliation(false)['rows'] as $control=>$row) {
            $evidence=$this->openingEvidence($control);
            if(!$journalCount && Money::compare($row['operational_amount'],'0')!==0)$evidence['classifications'][]='missing_opening_entry';
            $sourceProvided=$locked && $locked->approved_at && $locked->verified_at && !empty($locked->verification['report']['ready_for_review']);
            if($control==='cash_bank')$sourceProvided=$sourceProvided && isset($locked->expected_totals['cash'],$locked->expected_totals['bank']);
            else $sourceProvided=$sourceProvided && isset($locked->expected_totals[$sourceKeys[$control]]);
            $fingerprint=hash('sha256',json_encode([$control,$row['operational_amount'],$row['gl_amount'],$row['difference'],$evidence,$locked?->id,$sourceProvided]));
            $review=$reviews->get('opening-review:'.$control)?->details ?? [];
            if(isset($review['responsible_user_id']))$review['responsible_user_id']=(int)$review['responsible_user_id'];
            $stale=$review && ($review['fingerprint'] ?? '')!==$fingerprint;
            $rows[]=$row+['source_basis'=>'recorded_operational_balance_not_certified_opening','source_required'=>!$sourceProvided,
                'opening_status'=>!$sourceProvided?'BLOCKED_SOURCE_REQUIRED':($row['reconciled']?'PASS':'BLOCKED_RECONCILIATION'),
                'source_evidence'=>$evidence,'fingerprint'=>$fingerprint,'review'=>$review,'review_stale'=>(bool)$stale,
                'correction_url'=>$locked?'/onboarding':'/accounting?tab=settings'];
        }
        $people=\App\Models\User::where('company_id',$company->id)->where('is_active',true)->get(['id','name']);
        return ['currency'=>$company->base_currency,'as_of'=>CompanyClock::today()->toDateString(),'source_statement_available'=>(bool)$locked,
            'opening_finalized'=>(bool)$company->accounting_opening_finalized_at,'posted_journal_count'=>$journalCount,
            'status'=>collect($rows)->every(fn($r)=>$r['opening_status']==='PASS')?'PASS':'BLOCKED','rows'=>$rows,'reviewers'=>$people,
            'correction_policy'=>'Existing operational history must not be imported again through DC1. Review independent statements and prepare corrections through existing source workflows or DC2 accounting opening. Review notes never post or approve balances.'];
    }

    private function openingEvidence(string $control): array
    {
        $company=Auth::user()->company_id;
        $facts=['classifications'=>['missing_source_evidence','requires_accountant_review'],'flags'=>[],'records'=>[]];
        if($control==='accounts_receivable' || $control==='customer_advances') {
            $column=$control==='accounts_receivable'?'current_debt':'current_credit';
            $facts['records']=Customer::where($column,'>',0)->orderByDesc($column)->limit(10)->get(['id','name',$column])->map(fn($c)=>['reference'=>$c->name,'amount'=>$c->$column,'kind'=>'customer_balance','url'=>'/customer-debts?customer='.$c->id])->all();
            if($control==='accounts_receivable') {
                $invoiceExamples=\App\Models\Invoice::where('document_type','invoice')->whereIn('status',['issued','partially_paid','paid'])->whereNull('daily_sale_id')->whereDoesntHave('debtTransactions')->orderBy('id')->limit(10)->get();
                foreach($invoiceExamples as $invoice)$facts['records'][]=['reference'=>$invoice->invoice_number ?: 'Invoice #'.$invoice->id,'amount'=>Money::maximum('0',(string)$invoice->remaining_balance),'currency'=>$invoice->currency,'status'=>$invoice->status,'kind'=>'standalone_invoice_outstanding','url'=>'/invoices'];
            }
            $facts['legacy_ledger_rows']=\App\Models\CustomerDebtTransaction::where('source','like','legacy%')->count();
            $facts['unattributed_invoices']=\App\Models\Invoice::whereNull('customer_id')->whereIn('status',['issued','partially_paid','paid'])->count();
            $facts['paid_without_recorded_settlement']=\App\Models\Invoice::where('status','paid')->where('total_paid',0)->where('grand_total','>',0)->count();
            if($facts['legacy_ledger_rows'] || $facts['unattributed_invoices'])$facts['classifications'][]='unattributed_legacy_record';
            if($facts['paid_without_recorded_settlement'])$facts['flags'][]='paid_invoice_without_settlement';
            $facts['required_correction']='customer_statement_and_allocation_review';
        } elseif($control==='supplier_advances') {
            $payments=PurchaseOrderPayment::where('status','completed');
            $facts['payment_count']=(clone $payments)->count();
            $facts['missing_currency']=(clone $payments)->where(fn($q)=>$q->whereNull('currency')->orWhere('currency',''))->count();
            $facts['missing_account_link']=(clone $payments)->whereNull('financial_account_transaction_id')->count();
            $facts['records']=(clone $payments)->orderBy('id')->limit(20)->get()->map(fn($p)=>['reference'=>$p->reference_number ?: 'Payment #'.$p->id,'amount'=>$p->amount,'currency'=>$p->currency,'rate'=>$p->exchange_rate,'date'=>$p->payment_date?->toDateString(),'kind'=>'supplier_payment','url'=>$p->purchase_order_id?'/purchase-orders?po='.$p->purchase_order_id:'/accounting?tab=ap'])->all();
            if($facts['missing_currency'])$facts['flags'][]='supplier_currency_unproven';
            $facts['classifications'][]='unattributed_legacy_record';$facts['required_correction']='supplier_statement_currency_and_payment_review';
        } elseif($control==='inventory') {
            $stock=\App\Models\Product::where('quantity','>',0);
            $facts['missing_cost_count']=(clone $stock)->whereNull('inventory_value')->whereNull('weighted_average_cost')->whereNull('purchase_price')->count();
            $facts['zero_cost_count']=(clone $stock)->where('inventory_value',0)->where('purchase_price',0)->count();
            $facts['records']=(clone $stock)->orderByDesc('inventory_value')->limit(20)->get()->map(fn($p)=>['reference'=>$p->sku.' · '.$p->name,'amount'=>$p->inventory_value,'quantity'=>$p->quantity,'unit'=>$p->unit,'kind'=>'inventory_value','url'=>'/products?product='.$p->id])->all();
            if($facts['missing_cost_count'] || $facts['zero_cost_count'])$facts['flags'][]='inventory_cost_evidence_required';
            $facts['required_correction']='inventory_quantity_and_cost_statement';
        } elseif($control==='cash_bank') {
            $facts['account_count']=\App\Models\FinancialAccount::count();
            $facts['posted_account_transactions']=\App\Models\FinancialAccountTransaction::where('status','posted')->count();
            $facts['draft_sale_count']=\App\Models\DailySale::where('status','draft')->count();
            $facts['draft_sale_amount']=Money::normalize(\App\Models\DailySale::where('status','draft')->sum('total_amount'));
            $facts['unlinked_customer_payments']=\App\Models\CustomerDebtTransaction::where('type','payment')->whereNull('financial_account_id')->count();
            $facts['unlinked_invoice_payments']=\App\Models\PaymentTransaction::where('status','completed')->whereNull('financial_account_id')->count();
            $facts['records']=\App\Models\DailySale::orderBy('id')->limit(20)->get()->map(fn($sale)=>['reference'=>$sale->sale_number ?: 'Sale #'.$sale->id,'amount'=>$sale->total_amount,'paid_amount'=>$sale->paid_amount,'status'=>$sale->status,'date'=>$sale->sale_date?->toDateString(),'kind'=>'legacy_sale_not_confirmed_cash','url'=>'/daily-sales?date='.$sale->sale_date?->toDateString().'&sale='.$sale->id])->all();
            if($facts['draft_sale_count']){$facts['flags'][]='legacy_cash_formula_includes_drafts';$facts['classifications'][]='incorrect_classification';}
            $facts['classifications'][]='unattributed_legacy_record';$facts['required_correction']='bank_statements_cash_count_and_receipt_attribution';
        } else {
            $facts['records']=Expense::where('status','posted')->whereIn('document_type',['purchase_invoice','opening_balance'])->orderBy('id')->limit(20)->get()->map(fn($e)=>['reference'=>$e->document_number ?: 'Bill #'.$e->id,'amount'=>$e->total_amount,'currency'=>$e->currency,'kind'=>'supplier_bill','url'=>'/finance?expense='.$e->id])->all();
            $facts['required_correction']='supplier_payable_statement';
        }
        $facts['classifications']=array_values(array_unique($facts['classifications']));
        return $facts;
    }

    public function reviewOpeningControl(string $control,array $input): array
    {
        abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,'onboarding.review'),403);
        $data=validator($input,['classification'=>'required|in:missing_source_evidence,missing_opening_entry,incorrect_classification,timing_difference,unattributed_legacy_record,requires_accountant_review',
            'responsible_user_id'=>['nullable','integer',\Illuminate\Validation\Rule::exists('users','id')->where('company_id',Auth::user()->company_id)->where('is_active',true)],
            'note'=>'required|string|min:5|max:2000','fingerprint'=>'required|string|size:64','review_state'=>'required|in:source_required,under_review'])->validate();
        $data['responsible_user_id']=isset($data['responsible_user_id'])?(int)$data['responsible_user_id']:null;
        return \Illuminate\Support\Facades\DB::transaction(function()use($control,$data){
            \Illuminate\Support\Facades\DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();
            $current=collect($this->openingReview()['rows'])->firstWhere('control',$control);abort_unless($current,404);
            if(!hash_equals($current['fingerprint'],$data['fingerprint']))throw \Illuminate\Validation\ValidationException::withMessages(['fingerprint'=>'Source balances changed. Reload and review current evidence.']);
            $prior=\App\Models\AccountingException::where('exception_key','opening-review:'.$control)->first();$before=$prior?->details;
            $details=$data+['reviewed_by'=>Auth::id(),'reviewed_at'=>now()->toIso8601String(),'source_balance'=>$current['operational_amount'],'gl_balance'=>$current['gl_amount'],'difference'=>$current['difference']];
            $exception=\App\Models\AccountingException::updateOrCreate(['exception_key'=>'opening-review:'.$control],['type'=>'opening_review','severity'=>'warning','message'=>'Financial opening requires independent source review.','details'=>$details,'status'=>'open']);
            ActivityLog::create(['company_id'=>Auth::user()->company_id,'user_id'=>Auth::id(),'action'=>'accounting.opening.review_recorded','entity'=>'AccountingException','entity_id'=>$exception->id,'description'=>'Opening control review recorded; no financial posting or approval.','old_value'=>$before,'new_value'=>$details,'created_at'=>now()]);
            return $this->openingReview();
        });
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
              'forecast->30->currencies->'.$currency.'->expected_closing_cash as expected_closing_cash', 'evidence->cash->complete as cash_complete'])
            ->whereNull('evidence->archived')->latest('id')->first();
        if (! $r) {
            return ['state' => 'not_calculated', 'url' => '/financial-intelligence'];
        }

        return ['state' => 'ready', 'snapshot_id' => $r->id, 'as_of' => $r->as_of->toDateString(), 'currency' => $currency, 'horizon' => 30,
              'expected_collections' => $r->expected_inflows, 'scheduled_outflows' => $r->known_outflows, 'expected_closing_cash' => $r->expected_closing_cash, 'cash_complete'=>in_array($r->cash_complete,[true,1,'1','true'],true),
            'stale' => $r->evidence_cutoff->lt(now()->subMinutes(config('financial_intelligence.refresh_minutes'))) || Cache::has('finance-dirty:'.Auth::user()->company_id),
            'booked_accounting' => false, 'qualification' => 'Advisory timing from Financial Intelligence, separate from posted books. Review known commitments and predicted collection timing.', 'url' => '/financial-intelligence'];
    }
}
