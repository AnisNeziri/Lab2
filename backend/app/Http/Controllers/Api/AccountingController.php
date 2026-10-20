<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountingAccount;
use App\Models\AccountingException;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use App\Models\CustomerSettlementAllocation;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Services\AccountingJournalExperience;
use App\Services\AccountingRecoveryService;
use App\Services\AccountingReportQueries;
use App\Services\AccountingService;
use App\Services\CustomerSettlementService;
use App\Services\FinancialControlService;
use App\Services\ManualJournalService;
use App\Services\PayableReports;
use App\Services\PermissionService;
use App\Services\ReceivableReports;
use App\Support\CompanyClock;
use App\Support\CompanyCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function controlCenter(Request $r): JsonResponse
    {
        $dates = $this->dates($r);

        return response()->json(app(FinancialControlService::class)->summary($dates['from'] ?? null, $dates['to'] ?? null));
    }

    public function export(Request $r, string $report)
    {
        $filters = $r->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from','account_id'=>'nullable|integer|min:1','account_from'=>'nullable|string|max:30','account_to'=>'nullable|string|max:30','customer_id'=>'nullable|integer|min:1','supplier_id'=>'nullable|integer|min:1','currency'=>'nullable|string|size:3']);
        return app(\App\Services\AccountingExportService::class)->export($report,$filters);
    }

    public function parties(Request $r): JsonResponse
    {
        $d=$r->validate(['type'=>'required|in:customer,supplier','search'=>'nullable|string|max:100']);
        abort_unless(app(PermissionService::class)->roleHasPermission($r->user()->role,$d['type']==='customer'?'debts.view':'finance.view'),403);
        $model=$d['type']==='customer'?Customer::class:Supplier::class;
        return response()->json($model::query()->when($d['search']??null,fn($q,$s)=>$q->where('name','like','%'.$s.'%'))->orderBy('name')->limit(30)->get(['id','name']));
    }

    public function arSettlements(Customer $customer): JsonResponse
    {
        return response()->json(app(CustomerSettlementService::class)->workbench($customer));
    }

    public function allocateCustomerPayment(Request $r, CustomerDebtTransaction $transaction): JsonResponse
    {
        return response()->json(app(CustomerSettlementService::class)->allocate($transaction, $r->all()));
    }

    public function releaseCustomerAllocation(Request $r, CustomerSettlementAllocation $allocation): JsonResponse
    {
        return response()->json(app(CustomerSettlementService::class)->release($allocation, $r->all()));
    }

    public function initialize(): JsonResponse
    {
        return response()->json($this->accounting->initialize());
    }

    public function overview(Request $r): JsonResponse
    {
        $d = $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return response()->json($this->accounting->overview($d['from'] ?? null, $d['to'] ?? null));
    }

    public function accounts(): JsonResponse
    {
        return response()->json($this->accounting->accounts());
    }

    public function storeAccount(Request $r): JsonResponse
    {
        return response()->json($this->accounting->saveAccount($this->accountData($r)), 201);
    }

    public function updateAccount(Request $r, AccountingAccount $account): JsonResponse
    {
        return response()->json($this->accounting->saveAccount($this->accountData($r, $account), $account));
    }

    public function archiveAccount(AccountingAccount $account): JsonResponse
    {
        return response()->json($this->accounting->archiveAccount($account));
    }

    public function accountActivity(Request $r, AccountingAccount $account): JsonResponse
    {
        $d = $this->dates($r);
        $p = $r->validate(['per_page' => 'sometimes|integer|min:1|max:100']);

        return response()->json(app(AccountingReportQueries::class)->accountActivity($account, $d['from'] ?? null, $d['to'] ?? null, (int) ($p['per_page'] ?? 50)));
    }

    public function mappings(): JsonResponse
    {
        return response()->json($this->accounting->mappings());
    }

    public function saveMappings(Request $r): JsonResponse
    {
        $d = $r->validate(['mappings' => ['required', 'array'], 'mappings.*' => ['required', 'integer']]);

        return response()->json($this->accounting->saveMappings($d['mappings']));
    }

    public function controls(): JsonResponse
    {
        return response()->json($this->accounting->controls());
    }

    public function saveControls(Request $r): JsonResponse
    {
        $d = $r->validate(['fiscal_year_start_month' => ['sometimes', 'integer', 'min:1', 'max:12'], 'supplier_match_quantity_tolerance' => ['required', 'numeric', 'min:0', 'max:999999'], 'supplier_match_price_tolerance' => ['required', 'numeric', 'min:0', 'max:999999'], 'supplier_match_tax_tolerance' => ['required', 'numeric', 'min:0', 'max:999999']]);

        return response()->json($this->accounting->saveControls($d));
    }

    public function periods(): JsonResponse
    {
        return response()->json($this->accounting->periods());
    }

    public function storePeriod(Request $r): JsonResponse
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:100'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after_or_equal:starts_at']]);

        return response()->json($this->accounting->savePeriod($d), 201);
    }

    public function periodStatus(Request $r, AccountingPeriod $period): JsonResponse
    {
        $d = $r->validate(['status' => ['required', Rule::in(['open', 'soft_closed', 'closed'])], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        if ($period->status === 'closed' && $d['status'] !== 'closed' && ! app(PermissionService::class)->roleHasPermission($r->user()->role, 'accounting.periods.reopen')) {
            abort(403, 'Reopening a closed accounting period requires explicit permission.');
        }

        return response()->json($this->accounting->setPeriodStatus($period, $d['status'], $d['reason']));
    }

    public function periodReadiness(AccountingPeriod $period): JsonResponse
    {
        return response()->json($this->accounting->periodReadiness($period));
    }

    public function journals(Request $r): JsonResponse
    {
        $d = $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'status' => ['nullable', Rule::in(['draft', 'posted', 'reversed'])], 'account_id' => ['nullable', 'integer'], 'source_module' => ['nullable', 'string', 'max:50'], 'reference' => ['nullable', 'string', 'max:100'], 'user_id' => ['nullable', 'integer'], 'period_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json($this->accounting->journals($d));
    }

    public function journal(JournalEntry $journal): JsonResponse
    {
        return response()->json(app(AccountingJournalExperience::class)->detail($journal));
    }

    public function storeJournal(Request $r): JsonResponse
    {
        return response()->json(app(ManualJournalService::class)->save($this->journalData($r)), 201);
    }

    public function postJournal(Request $r, JournalEntry $journal): JsonResponse
    {
        return response()->json(app(ManualJournalService::class)->post($journal, $r->validate(['revision' => 'sometimes|integer|min:1', 'acknowledge_controls' => 'sometimes|boolean', 'control_reason' => 'nullable|string|max:1000', 'soft_close_adjustment' => 'sometimes|boolean'])));
    }

    public function reverseJournal(Request $r, JournalEntry $journal): JsonResponse
    {
        abort_unless($journal->source_module === 'manual', 422, 'Use the source transaction correction workflow; its subledger owns this journal.');
        $d = $r->validate(['posting_date' => ['required', 'date'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return response()->json($this->accounting->reverse($journal, $d['posting_date'], $d['reason']));
    }

    public function trialBalance(Request $r): JsonResponse
    {
        $d = $this->dates($r);

        return response()->json($this->accounting->trialBalance($d['from'] ?? null, $d['to'] ?? null));
    }

    public function profitLoss(Request $r): JsonResponse
    {
        $d = $this->dates($r);

        return response()->json($this->accounting->profitAndLoss($d['from'] ?? null, $d['to'] ?? null));
    }

    public function balanceSheet(Request $r): JsonResponse
    {
        $d = $r->validate(['to' => ['nullable', 'date']]);

        return response()->json($this->accounting->balanceSheet($d['to'] ?? null));
    }

    public function cashFlow(Request $r): JsonResponse
    {
        $d = $this->dates($r);

        return response()->json($this->accounting->cashFlow($d['from'] ?? null, $d['to'] ?? null));
    }

    public function integrity(): JsonResponse
    {
        return response()->json($this->accounting->integrity());
    }

    public function exceptions(Request $r):JsonResponse
    {
        $d=$r->validate(['page'=>'nullable|integer|min:1']);
        return response()->json(AccountingException::query()->where('status','open')->with(['attempts'=>fn($q)=>$q->limit(10),'attempts.user:id,name'])->orderByRaw("CASE severity WHEN 'critical' THEN 0 ELSE 1 END")->latest('id')->paginate(50));
    }

    public function reconciliation(Request $r): JsonResponse
    {
        $d=$r->validate(['to'=>'nullable|date']);
        return response()->json(isset($d['to'])?app(\App\Services\DatedFinancialReconciliation::class)->report($d['to']):$this->accounting->reconciliation(false));
    }

    public function retryException(Request $r, AccountingException $exception, AccountingRecoveryService $recovery): JsonResponse
    {
        $d = $r->validate(['counter_accounting_account_id' => ['nullable', 'integer', Rule::exists('accounting_accounts', 'id')->where('company_id', $r->user()->company_id)]]);

        return response()->json($recovery->retry($exception, $d));
    }

    public function openingSetup(): JsonResponse
    {
        return response()->json($this->accounting->openingSetup());
    }

    public function finalizeOpening(Request $r): JsonResponse
    {
        $company = $r->user()->company_id;
        $d = $r->validate(['accounting_start_date' => ['required', 'date'], 'balances' => ['required', 'array', 'min:2'], 'balances.*.accounting_account_id' => ['required', 'integer', Rule::exists('accounting_accounts', 'id')->where('company_id', $company)], 'balances.*.description' => ['nullable', 'string', 'max:500'], 'balances.*.debit' => ['nullable', 'numeric', 'min:0'], 'balances.*.credit' => ['nullable', 'numeric', 'min:0']]);

        return response()->json($this->accounting->finalizeOpening($d['accounting_start_date'], $d['balances']), 201);
    }

    public function updateJournal(Request $r, JournalEntry $journal): JsonResponse
    {
        return response()->json(app(ManualJournalService::class)->save($this->journalData($r), $journal));
    }

    public function correctJournal(Request $r, JournalEntry $journal): JsonResponse
    {
        $d = $r->validate(['posting_date' => 'required|date', 'reason' => 'required|string|min:10|max:1000']);

        return response()->json(app(ManualJournalService::class)->correct($journal, $d['posting_date'], $d['reason']), 201);
    }

    public function sourceJournals(Request $r): JsonResponse
    {
        $d = $r->validate(['entity_type' => 'required|string', 'entity_id' => 'required|integer|min:1']);

        return response()->json(app(AccountingJournalExperience::class)->sourceLinks($d['entity_type'], $d['entity_id']));
    }

    public function customerStatement(Request $r, Customer $customer): JsonResponse
    {
        $d = $r->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from', 'currency' => 'nullable|string|size:3', 'page' => 'nullable|integer|min:1']);

        return response()->json(app(ReceivableReports::class)->statement($customer, $d['from'], $d['to'], $d['currency'] ?? CompanyCurrency::current()));
    }

    public function payableAging(Request $r): JsonResponse
    {
        $d = $r->validate(['as_of' => 'nullable|date', 'supplier_id' => 'nullable|integer|min:1', 'currency' => 'nullable|string|size:3', 'page' => 'nullable|integer|min:1']);

        return response()->json(app(PayableReports::class)->aging($d['as_of'] ?? CompanyClock::today()->toDateString(), $d));
    }

    public function supplierWorkbench(Supplier $supplier): JsonResponse
    {
        return response()->json(app(PayableReports::class)->workbench($supplier));
    }

    public function supplierStatement(Request $r,Supplier $supplier):JsonResponse
    {
        $d=$r->validate(['from'=>'required|date','to'=>'required|date|after_or_equal:from','page'=>'nullable|integer|min:1']);
        return response()->json(app(\App\Services\SupplierStatementQueries::class)->statement($supplier,$d['from'],$d['to']));
    }

    private function dates(Request $r): array
    {
        return $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
    }

    private function accountData(Request $r, ?AccountingAccount $account = null): array
    {
        $company = $r->user()->company_id;

        return $r->validate(['code' => ['required', 'string', 'max:30', Rule::unique('accounting_accounts', 'code')->where('company_id', $company)->ignore($account?->id)], 'name' => ['required', 'string', 'max:160'], 'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])], 'parent_id' => ['nullable', 'integer', Rule::exists('accounting_accounts', 'id')->where('company_id', $company)], 'normal_balance' => ['required', Rule::in(['debit', 'credit'])], 'is_posting' => ['required', 'boolean'], 'is_active' => ['sometimes', 'boolean'], 'statement_group' => ['nullable', Rule::in(['revenue', 'cost_of_sales', 'operating_expense', 'other_income', 'other_expense'])], 'cash_flow_class' => ['nullable', Rule::in(['operating', 'investing', 'financing'])], 'description' => ['nullable', 'string', 'max:1000']]);
    }

    private function journalData(Request $r): array
    {
        $company = $r->user()->company_id;

        return $r->validate(['posting_date' => ['required', 'date'], 'reference_number' => ['nullable', 'string', 'max:100'], 'description' => ['required', 'string', 'max:1000'], 'currency' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'source_module' => ['prohibited'], 'source_type' => ['prohibited'], 'source_id' => ['prohibited'], 'source_key' => ['prohibited'], 'revision' => ['sometimes', 'integer', 'min:1'], 'acknowledge_controls' => ['sometimes', 'boolean'], 'control_reason' => ['nullable', 'string', 'max:1000'], 'exchange_rate_date' => ['nullable', 'date'], 'exchange_rate_source' => ['nullable', 'string', 'max:160'], 'lines' => ['required', 'array', 'min:2', 'max:100'], 'lines.*.dimensions' => ['nullable', 'array:warehouse_id,sales_channel'], 'lines.*.dimensions.warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $company)], 'lines.*.dimensions.sales_channel' => ['nullable', 'in:retail,wholesale,online,other'], 'lines.*.accounting_account_id' => ['required', 'integer', Rule::exists('accounting_accounts', 'id')->where('company_id', $company)], 'lines.*.description' => ['nullable', 'string', 'max:500'], 'lines.*.debit' => ['nullable', 'numeric', 'min:0'], 'lines.*.credit' => ['nullable', 'numeric', 'min:0'], 'lines.*.foreign_debit' => ['nullable', 'numeric', 'min:0'], 'lines.*.foreign_credit' => ['nullable', 'numeric', 'min:0'], 'lines.*.counterparty_type' => ['nullable', 'string', 'max:30'], 'lines.*.counterparty_id' => ['nullable', 'integer']]);
    }
}
