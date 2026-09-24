<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{AccountingAccount,AccountingException,AccountingPeriod,JournalEntry};
use App\Services\{AccountingRecoveryService,AccountingService};
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Validation\Rule;

class AccountingController extends Controller
{
    public function __construct(private readonly AccountingService $accounting){}
    public function initialize():JsonResponse{return response()->json($this->accounting->initialize());}
    public function overview(Request $r):JsonResponse{$d=$r->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from']]);return response()->json($this->accounting->overview($d['from']??null,$d['to']??null));}
    public function accounts():JsonResponse{return response()->json($this->accounting->accounts());}
    public function storeAccount(Request $r):JsonResponse{return response()->json($this->accounting->saveAccount($this->accountData($r)),201);}
    public function updateAccount(Request $r,AccountingAccount $account):JsonResponse{return response()->json($this->accounting->saveAccount($this->accountData($r,$account),$account));}
    public function archiveAccount(AccountingAccount $account):JsonResponse{return response()->json($this->accounting->archiveAccount($account));}
    public function mappings():JsonResponse{return response()->json($this->accounting->mappings());}
    public function saveMappings(Request $r):JsonResponse{$d=$r->validate(['mappings'=>['required','array'],'mappings.*'=>['required','integer']]);return response()->json($this->accounting->saveMappings($d['mappings']));}
    public function controls():JsonResponse{return response()->json($this->accounting->controls());}
    public function saveControls(Request $r):JsonResponse{$d=$r->validate(['supplier_match_quantity_tolerance'=>['required','numeric','min:0','max:999999'],'supplier_match_price_tolerance'=>['required','numeric','min:0','max:999999'],'supplier_match_tax_tolerance'=>['required','numeric','min:0','max:999999']]);return response()->json($this->accounting->saveControls($d));}
    public function periods():JsonResponse{return response()->json($this->accounting->periods());}
    public function storePeriod(Request $r):JsonResponse{$d=$r->validate(['name'=>['required','string','max:100'],'starts_at'=>['required','date'],'ends_at'=>['required','date','after_or_equal:starts_at']]);return response()->json($this->accounting->savePeriod($d),201);}
    public function periodStatus(Request $r,AccountingPeriod $period):JsonResponse{$d=$r->validate(['status'=>['required',Rule::in(['open','soft_closed','closed'])],'reason'=>['required','string','min:5','max:1000']]);if($period->status==='closed'&&$d['status']!=='closed'&&!app(\App\Services\PermissionService::class)->roleHasPermission($r->user()->role,'accounting.periods.reopen'))abort(403,'Reopening a closed accounting period requires explicit permission.');return response()->json($this->accounting->setPeriodStatus($period,$d['status'],$d['reason']));}
    public function periodReadiness(AccountingPeriod $period):JsonResponse{return response()->json($this->accounting->periodReadiness($period));}
    public function journals(Request $r):JsonResponse{$d=$r->validate(['from'=>['nullable','date'],'to'=>['nullable','date'],'status'=>['nullable',Rule::in(['draft','posted','reversed'])],'per_page'=>['nullable','integer','min:1','max:100']]);return response()->json($this->accounting->journals($d));}
    public function journal(JournalEntry $journal):JsonResponse{return response()->json($journal->load(['lines.account','creator:id,name','poster:id,name','reversal','reversalOf']));}
    public function storeJournal(Request $r):JsonResponse{return response()->json($this->accounting->createDraft($this->journalData($r)),201);}
    public function postJournal(JournalEntry $journal):JsonResponse{return response()->json($this->accounting->post($journal));}
    public function reverseJournal(Request $r,JournalEntry $journal):JsonResponse{$d=$r->validate(['posting_date'=>['required','date'],'reason'=>['required','string','min:5','max:1000']]);return response()->json($this->accounting->reverse($journal,$d['posting_date'],$d['reason']));}
    public function trialBalance(Request $r):JsonResponse{$d=$this->dates($r);return response()->json($this->accounting->trialBalance($d['from']??null,$d['to']??null));}
    public function profitLoss(Request $r):JsonResponse{$d=$this->dates($r);return response()->json($this->accounting->profitAndLoss($d['from']??null,$d['to']??null));}
    public function balanceSheet(Request $r):JsonResponse{$d=$r->validate(['to'=>['nullable','date']]);return response()->json($this->accounting->balanceSheet($d['to']??null));}
    public function cashFlow(Request $r):JsonResponse{$d=$this->dates($r);return response()->json($this->accounting->cashFlow($d['from']??null,$d['to']??null));}
    public function integrity():JsonResponse{return response()->json($this->accounting->integrity());}
    public function reconciliation():JsonResponse{return response()->json($this->accounting->reconciliation(true));}
    public function retryException(Request $r,AccountingException $exception,AccountingRecoveryService $recovery):JsonResponse{$d=$r->validate(['counter_accounting_account_id'=>['nullable','integer',Rule::exists('accounting_accounts','id')->where('company_id',$r->user()->company_id)]]);return response()->json($recovery->retry($exception,$d));}
    public function openingSetup():JsonResponse{return response()->json($this->accounting->openingSetup());}
    public function finalizeOpening(Request $r):JsonResponse{$company=$r->user()->company_id;$d=$r->validate(['accounting_start_date'=>['required','date'],'balances'=>['required','array','min:2'],'balances.*.accounting_account_id'=>['required','integer',Rule::exists('accounting_accounts','id')->where('company_id',$company)],'balances.*.description'=>['nullable','string','max:500'],'balances.*.debit'=>['nullable','numeric','min:0'],'balances.*.credit'=>['nullable','numeric','min:0']]);return response()->json($this->accounting->finalizeOpening($d['accounting_start_date'],$d['balances']),201);}
    private function dates(Request $r):array{return $r->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from']]);}
    private function accountData(Request $r,?AccountingAccount $account=null):array{$company=$r->user()->company_id;return $r->validate(['code'=>['required','string','max:30',Rule::unique('accounting_accounts','code')->where('company_id',$company)->ignore($account?->id)],'name'=>['required','string','max:160'],'type'=>['required',Rule::in(['asset','liability','equity','revenue','expense'])],'parent_id'=>['nullable','integer',Rule::exists('accounting_accounts','id')->where('company_id',$company)],'normal_balance'=>['required',Rule::in(['debit','credit'])],'is_posting'=>['required','boolean'],'is_active'=>['sometimes','boolean'],'cash_flow_class'=>['nullable',Rule::in(['operating','investing','financing'])],'description'=>['nullable','string','max:1000']]);}
    private function journalData(Request $r):array{$company=$r->user()->company_id;return $r->validate(['posting_date'=>['required','date'],'reference_number'=>['nullable','string','max:100'],'description'=>['required','string','max:1000'],'currency'=>['nullable','string','size:3'],'exchange_rate'=>['nullable','numeric','gt:0'],'source_module'=>['nullable','string','max:50'],'source_type'=>['nullable','string','max:100'],'source_id'=>['nullable','integer'],'source_key'=>['nullable','string','max:160'],'lines'=>['required','array','min:2'],'lines.*.accounting_account_id'=>['required','integer',Rule::exists('accounting_accounts','id')->where('company_id',$company)],'lines.*.description'=>['nullable','string','max:500'],'lines.*.debit'=>['nullable','numeric','min:0'],'lines.*.credit'=>['nullable','numeric','min:0'],'lines.*.foreign_debit'=>['nullable','numeric','min:0'],'lines.*.foreign_credit'=>['nullable','numeric','min:0'],'lines.*.counterparty_type'=>['nullable','string','max:30'],'lines.*.counterparty_id'=>['nullable','integer']]);}
}
