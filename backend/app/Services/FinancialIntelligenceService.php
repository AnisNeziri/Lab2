<?php
namespace App\Services;
use App\Models\{FinancialIntelligenceSnapshot as Snapshot,FinancialIntelligenceObservation as Observation,FinancialIntelligencePolicy as Policy,Company,User,Customer,PurchaseOrder,Expense,Shipment,OperationalTask};
use App\Support\Money;
use Illuminate\Support\Facades\{Auth,Cache,Date,DB,Log};
use Illuminate\Support\Str;

final class FinancialIntelligenceService {
 public function authorize():void {abort_unless(Auth::user()?->company_id,403);foreach(['analytics.finance','finance.view','financial_accounts.view'] as $p)abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,$p),403,'Financial Intelligence requires finance and cash-account access.');}
 public function latest(int $horizon=30):array {
  $this->authorize();abort_unless(in_array($horizon,[7,30,60,90]),422,'Choose a 7, 30, 60 or 90 day horizon.');
  $r=Snapshot::whereNull('evidence->archived')->latest('id')->first();if(!$r)return ['state'=>'not_calculated','message'=>'No frozen forecast yet. Refresh financial evidence or wait for the scheduled calculation.','horizon'=>$horizon];
  return ['state'=>'ready','id'=>$r->id,'version'=>$r->version,'as_of'=>$r->as_of->toDateString(),'evidence_cutoff'=>$r->evidence_cutoff->toIso8601String(),'stale'=>$r->evidence_cutoff->lt(now()->subMinutes(config('financial_intelligence.refresh_minutes')))||Cache::has('finance-dirty:'.Auth::user()->company_id),'evidence'=>$r->evidence,'forecast'=>$r->forecast[(string)$horizon],'evaluation'=>$r->evaluation,'model'=>$this->modelStatus(),'read_only'=>true];
 }
 public function refresh():array {
  $this->authorize();$lock=Cache::lock('finance-refresh:'.Auth::user()->company_id,120);abort_unless($lock->get(),409,'Financial evidence is already being refreshed.');
  try{return DB::transaction(function(){
   $status=$this->modelStatus();$e=app(FinancialIntelligenceEvidence::class)->assemble($status['champion']);$stable=$e;unset($stable['cutoff']);$hash=hash('sha256',json_encode([$stable,config('financial_intelligence.version')],JSON_PRESERVE_ZERO_FRACTION));$old=Snapshot::latest('id')->first();
   $forecasts=[];foreach([7,30,60,90] as $h)$forecasts[(string)$h]=app(FinancialForecastMath::class)->calculate($e,$h);
   $r=Snapshot::firstOrCreate(['fingerprint'=>$hash],['company_id'=>Auth::user()->company_id,'version'=>(string)Str::uuid(),'as_of'=>$e['as_of'],'evidence_cutoff'=>now(),'evidence'=>$e,'forecast'=>$forecasts]);
   Observation::firstOrCreate(['observation_date'=>today()->toDateString()],['company_id'=>Auth::user()->company_id,'observed_at'=>now(),'facts'=>['cash'=>$e['cash'],'receivables'=>$e['receivables'],'customers'=>$e['customers'],'commitments'=>$e['commitments'],'payables'=>$e['payable_observations'],'inventory'=>$e['inventory']['total'],'qualification'=>'First point-in-time sample of this date; not a reconstructed end-of-day balance.']]);
   if($r->wasRecentlyCreated)$this->events($r,$old);$this->evaluate();Cache::forget('finance-dirty:'.Auth::user()->company_id);return $this->latest();
  });}finally{$lock->release();}
 }
 public function receivables(?int $customer=null):array {$r=$this->latest();if($customer)Customer::withTrashed()->findOrFail($customer);return ['rows'=>array_values(array_filter($r['evidence']['receivables']??[],fn($x)=>!$customer||$x['customer_id']===$customer)),'customers'=>array_values(array_filter($r['evidence']['customers']??[],fn($x)=>!$customer||$x['id']===$customer)),'as_of'=>$r['as_of']??null];}
 public function section(string $key):array {$r=$this->latest();return ['data'=>$r['evidence'][$key]??[],'as_of'=>$r['as_of']??null,'state'=>$r['state']];}
 public function pressure():array {$r=$this->latest(90);return ['rows'=>array_merge(...array_values(array_map(fn($c)=>$c['pressure'],$r['forecast']['currencies']??[]))),'as_of'=>$r['as_of']??null];}
 public function policy(array $input):array {
  $this->authorize();abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,'financial_accounts.manage'),403);
  $v=validator($input,['cash_coverage_confirmed'=>'sometimes|boolean','minimum_cash'=>'sometimes|array','minimum_cash.*'=>'numeric|min:0|max:1000000000','terms'=>'sometimes|array|max:200','terms.*.basis'=>'required|in:fixed_date,arrival','terms.*.reference'=>'required|string|min:3|max:300','terms.*.shipment_id'=>'required_if:terms.*.basis,arrival|nullable|integer|min:1','terms.*.offset_days'=>'sometimes|integer|min:0|max:180','split_permissions'=>'sometimes|array|max:100','split_permissions.*.allowed'=>'required|boolean','split_permissions.*.reference'=>'required|string|min:3|max:300'])->validate();
  foreach($v['minimum_cash']??[] as $currency=>$amount){abort_unless(preg_match('/^[A-Z]{3}$/',$currency),422,'Use a three-letter currency code.');$v['minimum_cash'][$currency]=Money::normalize($amount);}
  foreach($v['terms']??[] as $key=>$term){abort_unless(preg_match('/^(po|expense):([1-9][0-9]*)$/',$key,$m),422,'Use an existing PO or supplier-document source.');$source=$m[1]==='po'?PurchaseOrder::findOrFail((int)$m[2]):Expense::findOrFail((int)$m[2]);
   if($term['basis']==='arrival'){$s=Shipment::findOrFail($term['shipment_id']);$po=$m[1]==='po'?$source->id:$source->purchase_order_id;abort_unless($po&&($s->purchase_order_id==$po||$s->purchaseOrders()->whereKey($po)->exists()),422,'The shipment must be linked to this obligation’s Purchase Order.');}
  }
  foreach($v['split_permissions']??[] as $supplier=>$term)\App\Models\Supplier::findOrFail((int)$supplier);
  $prior=Policy::latest('id')->first()?->settings??[];$settings=array_replace_recursive($prior,$v);$p=Policy::create(['company_id'=>Auth::user()->company_id,'version'=>(string)Str::uuid(),'settings'=>$settings,'created_by'=>Auth::id()]);
  app(AnalyticsDataService::class)->audit('finance.intelligence.policy_changed',['policy_id'=>$p->id,'settings'=>$settings]);Cache::put('finance-dirty:'.Auth::user()->company_id,true,86400);return ['version'=>$p->version,'settings'=>$settings,'qualification'=>'Forecast assumptions and documented terms only; no contractual date or accounting balance is changed.'];
 }
 public function modelStatus():array {
  return Cache::remember('finance-model:'.Auth::user()->company_id,60,fn()=>$this->calculateModelStatus());
 }
 private function calculateModelStatus():array {
  $p=Policy::latest('id')->first()?->settings??[];$pairs=[];foreach(Snapshot::whereNull('evidence->archived')->whereNotNull('evaluation')->orderBy('id')->get() as $r)foreach($r->evaluation['collections']??[] as $x)if(($x['historical_error']??null)!==null&&!isset($pairs[$x['key']]))$pairs[$x['key']]=$x;
  $n=count($pairs);$due=$n?array_sum(array_column($pairs,'due_error'))/$n:null;$median=$n?array_sum(array_column($pairs,'historical_error'))/$n:null;
  return ['baseline'=>'due_date_baseline','champion'=>$p['champion']??'due_date_baseline','challenger'=>'historical_median','completed_observations'=>$n,'baseline_mae_days'=>$due,'challenger_mae_days'=>$median,'eligible_for_promotion'=>$n>=config('financial_intelligence.promotion_samples')&&$median<$due*.95,'minimum_completed'=>config('financial_intelligence.promotion_samples'),'qualification'=>'Local interpretable timing statistics. No trained credit score or verified production accuracy yet.'];
 }
 public function selectModel(array $input):array {
  $this->authorize();abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,'analytics.ml_datasets'),403);$v=validator($input,['model'=>'required|in:due_date_baseline,historical_median','reason'=>'required|string|min:5|max:500'])->validate();$status=$this->modelStatus();abort_if($v['model']==='historical_median'&&!$status['eligible_for_promotion'],422,'The challenger needs enough genuine completed outcomes and lower timing error than the due-date baseline.');
  $settings=Policy::latest('id')->first()?->settings??[];$settings['champion']=$v['model'];$settings['model_decision']=['reason'=>$v['reason'],'previous'=>$status['champion'],'metrics'=>$status,'at'=>now()->toIso8601String()];Policy::create(['company_id'=>Auth::user()->company_id,'version'=>(string)Str::uuid(),'settings'=>$settings,'created_by'=>Auth::id()]);app(AnalyticsDataService::class)->audit('finance.intelligence.model_selected',$settings['model_decision']);Cache::forget('finance-model:'.Auth::user()->company_id);return $this->refresh();
 }
 public function evaluate():void {
  Cache::forget('finance-model:'.Auth::user()->company_id);
  foreach(Snapshot::whereNull('evidence->archived')->whereDate('as_of','<',today())->whereDate('as_of','>=',today()->subDays(365))->get() as $r){$evaluation=$r->evaluation??['cash'=>[],'collections'=>[]];
   foreach([7,30,60,90] as $h){$target=$r->as_of->copy()->addDays($h);if(!$target->lt(today())||isset($evaluation['cash'][$h]))continue;$actual=Observation::whereNull('facts->archived')->whereDate('observation_date',$target)->where('observed_at','>',$r->evidence_cutoff)->first();if(!$actual)continue;$scores=[];
    $ids=array_column($r->evidence['cash']['accounts'],'id');$actualIds=array_column($actual->facts['cash']['accounts'],'id');sort($ids);sort($actualIds);if($ids!==$actualIds)continue;
    foreach($r->forecast[$h]['currencies'] as $currency=>$f){$amount=$actual->facts['cash']['by_currency'][$currency]??null;if($amount!==null&&$f['expected_closing_cash']!==null)$scores[$currency]=['expected'=>$f['expected_closing_cash'],'actual'=>$amount,'absolute_error'=>Money::normalize(abs(Money::minor(Money::subtract($amount,$f['expected_closing_cash'])))/100),'coverage_complete'=>$r->evidence['cash']['complete']&&$actual->facts['cash']['complete']];}
    $evaluation['cash'][$h]=['observed_at'=>$actual->observed_at->toIso8601String(),'currencies'=>$scores,'qualification'=>'Matched-account point-in-time comparison; includes unforecast future transactions, not causal model proof.'];
   }
   // Only labels known AFTER prediction, with genuine completed cash allocations.
   $customers=Customer::withTrashed()->whereIn('id',array_column($r->evidence['receivables'],'customer_id'))->get()->keyBy('id');
   foreach($r->evidence['receivables'] as $row){if($row['source_type']!=='customer_ledger'||isset($evaluation['collections'][$row['key']])||empty($row['due_date']))continue;$customer=$customers[$row['customer_id']]??null;if(!$customer)continue;
    $sample=collect(app(FinancialPaymentTiming::class)->history($customer)['samples'])->first(fn($s)=>$s['obligation_id']===$row['source_id']&&Date::parse($s['known_at'])->gt($r->evidence_cutoff)&&$s['paid_date']>$r->as_of->toDateString());if(!$sample)continue;
    $err=fn($d)=>$d===null?null:abs((int)Date::parse($d)->diffInDays(Date::parse($sample['paid_date']),false));$evaluation['collections'][$row['key']]=['key'=>$row['key'],'actual_paid_date'=>$sample['paid_date'],'due_error'=>$err($row['due_date_baseline']),'historical_error'=>$err($row['historical_date']),'known_at'=>$sample['known_at']];
   }
   if($evaluation!==$r->evaluation)$r->update(['evaluation'=>$evaluation]);
  }
 }
 private function events(Snapshot $r,?Snapshot $old):void {
  $events=[];$pressure=array_merge(...array_values(array_map(fn($c)=>$c['pressure'],$r->forecast[30]['currencies'])));$prior=$old?array_merge(...array_values(array_map(fn($c)=>$c['pressure'],$old->forecast[30]['currencies']))):[];
  if($pressure&&!$prior)$events['cash_pressure_detected']=['periods'=>count($pressure),'confidence'=>$r->evidence['confidence']];
  $before=collect($old?->evidence['receivables']??[])->keyBy('key');foreach($r->evidence['receivables'] as $x)if(in_array($x['risk'],['OVERDUE','HIGH_RISK'])&&($before[$x['key']]['risk']??null)!==$x['risk'])$events['receivable_risk_changed']=['risk'=>$x['risk'],'customer_id'=>$x['customer_id']];
  $large=fn($rows)=>array_values(array_filter($rows,fn($x)=>Money::compare($x['amount'],config('financial_intelligence.large_commitment'))>=0&&$x['expected_date']&&$x['expected_date']<=today()->addDays(30)->toDateString()));$largeNow=$large($r->evidence['commitments']);$largeOld=$large($old?->evidence['commitments']??[]);
  if(array_diff(array_column($largeNow,'key'),array_column($largeOld,'key')))$events['large_commitment_upcoming']=['commitments'=>count($largeNow)];
  if($old)foreach($r->forecast[30]['currencies'] as $currency=>$f){$prev=$old->forecast[30]['currencies'][$currency]??null;if($prev&&Money::compare(abs(Money::minor(Money::subtract($f['net_change'],$prev['net_change'])))/100,config('financial_intelligence.material_change'))>=0)$events['forecast_materially_changed']=['currency'=>$currency,'confidence'=>$r->evidence['confidence']];}
  $codes=array_column($r->evidence['health'],'code');$oldCodes=array_column($old?->evidence['health']??[],'code');if(array_diff($codes,$oldCodes))$events['data_quality_issue']=['issue_count'=>count($codes)];
  foreach($events as $name=>$metadata){app(BusinessEventService::class)->record('finance.intelligence.'.$name,$r,'Financial forecast',$metadata,'finance:'.$r->version.':'.$name);
   // One active task per issue family, with a guarded financial source. No amounts
   // in task titles/descriptions or generic event payloads.
   $key=hash('sha256','finance:'.$r->company_id.':'.$name);$task=OperationalTask::firstOrCreate(['dedupe_key'=>$key],['company_id'=>$r->company_id,'title'=>'Review financial intelligence: '.str_replace('_',' ',$name),'description'=>'Review current evidence, unknowns and source documents. Advisory only.','priority'=>$name==='cash_pressure_detected'?'high':'normal','assigned_role'=>'admin','source_type'=>'FinancialIntelligenceSnapshot','source_id'=>$r->id,'created_by'=>Auth::id(),'due_at'=>today()->addDay()]);
   if(in_array($task->status,['completed','cancelled']))$task->update(['status'=>'open','source_id'=>$r->id,'completed_at'=>null]);
  }
 }
 public function scheduled():int {
  $previous=Auth::user();$done=0;$start=microtime(true);try{foreach(Company::orderBy('id')->cursor() as $company){if(microtime(true)-$start>config('financial_intelligence.worker_seconds'))break;$actor=User::withoutGlobalScopes()->where('company_id',$company->id)->whereIn('role',['admin','manager'])->where('is_active',true)->first();if(!$actor)continue;Auth::setUser($actor);try{$latest=Snapshot::latest('id')->first();if(!$latest||($latest->evidence['calculation_version']??null)!==config('financial_intelligence.version')||$latest->evidence_cutoff->lt(now()->subMinutes(config('financial_intelligence.refresh_minutes')))||Cache::has('finance-dirty:'.$company->id)){$this->refresh();$done++;}}catch(\Throwable $e){Log::warning('Financial intelligence refresh deferred.',['company_id'=>$company->id,'error'=>$e->getMessage()]);}}}finally{$previous?Auth::setUser($previous):Auth::forgetUser();}return $done;
 }
}
