<?php
\Illuminate\Support\Facades\Artisan::command('simulation:work {--once}',function(){do{$this->call('queue:work',['connection'=>'database','--queue'=>'strategic-simulation','--stop-when-empty'=>true,'--max-jobs'=>1,'--tries'=>1,'--timeout'=>240]);if(!$this->option('once'))sleep(2);}while(!$this->option('once'));})->purpose('Run isolated strategic simulation jobs locally');
\Illuminate\Support\Facades\Artisan::command('supply-optimizer:work {--once}',function(){
 do {$this->call('queue:work',['connection'=>'database','--queue'=>'supply-optimizer','--stop-when-empty'=>true,'--max-jobs'=>1,'--tries'=>1,'--timeout'=>240]);
  if(!$this->option('once'))sleep(2);
 }while(!$this->option('once'));
})->purpose('Run the local bounded supply optimization queue');
\Illuminate\Support\Facades\Artisan::command('supply-optimizer:maintain',function(){
 $old=\Illuminate\Support\Facades\Auth::user();$count=0;
 try{foreach(\App\Models\Company::orderBy('id')->cursor() as $company){$actor=\App\Models\User::withoutGlobalScopes()->where('company_id',$company->id)->where('role','admin')->first();if(!$actor)continue;\Illuminate\Support\Facades\Auth::setUser($actor);try{$count+=app(\App\Services\SupplyOptimizerService::class)->maintain();}catch(\Throwable $e){\Illuminate\Support\Facades\Log::warning('Optimizer maintenance skipped',['company_id'=>$company->id]);}}}
 finally{if($old)\Illuminate\Support\Facades\Auth::setUser($old);else \Illuminate\Support\Facades\Auth::forgetUser();}$this->info($count.' plans became stale.');
});
\Illuminate\Support\Facades\Schedule::command('supply-optimizer:maintain')->everyMinute()->withoutOverlapping();
\Illuminate\Support\Facades\Artisan::command('decision-learning:maintain',function(){ $this->info('Processed '.app(\App\Services\DecisionLearningService::class)->scheduled().' learning sources; production policies unchanged.'); });
\Illuminate\Support\Facades\Schedule::command('decision-learning:maintain')->hourly()->withoutOverlapping();
\Illuminate\Support\Facades\Artisan::command('decision-learning:status',function(){
 $rows=[];foreach(\App\Models\Company::orderBy('id')->cursor() as $c){$q=\App\Models\DecisionLearningRecord::withoutGlobalScopes()->where('company_id',$c->id)->whereNull('payload->archived');$counts=(clone $q)->selectRaw('kind, COUNT(*) as total')->groupBy('kind')->pluck('total','kind')->all();if(!$counts)continue;$rows[]=['company'=>$c->name,'company_id'=>$c->id,'records'=>$counts,'completed_by_domain'=>(clone $q)->whereIn('kind',['outcome','prediction_outcome'])->selectRaw('domain, COUNT(*) as total')->groupBy('domain')->pluck('total','domain')->all(),'promotions'=>(clone $q)->where('kind','policy_change')->where('payload->action','promote')->count()];}$this->line(json_encode($rows,JSON_PRETTY_PRINT));
})->purpose('Read derived learning counts without changing data');
\Illuminate\Support\Facades\Artisan::command('customer-intelligence:refresh {--force}',function(){ $this->info('Updated '.app(\App\Services\CustomerSalesIntelligenceService::class)->scheduled((bool)$this->option('force')).' company customer snapshots.'); });
\Illuminate\Support\Facades\Schedule::command('customer-intelligence:refresh')->hourly()->withoutOverlapping();
\Illuminate\Support\Facades\Artisan::command('customer-intelligence:status',function(){
    $rows=[];foreach(\App\Models\Company::orderBy('id')->cursor() as $company){
        $latest=\App\Models\CustomerSalesSnapshot::withoutGlobalScopes()->where('company_id',$company->id)->whereNull('evidence->archived')->latest('id')->first();if(!$latest)continue;
        $predictions=\App\Models\CustomerSalesPrediction::withoutGlobalScopes()->where('company_id',$company->id)->where('model_key','customer-sales-v8')->whereNull('value->archived')->get();
        $policy=\App\Models\CustomerIntelligencePolicy::withoutGlobalScopes()->where('company_id',$company->id)->latest('id')->first();
        $rows[]=['company_id'=>$company->id,'as_of'=>$latest->as_of->toDateString(),'canonical_baskets'=>$latest->evidence['canonical_baskets'],'customers_with_history'=>count(array_filter($latest->evidence['profiles'],fn($p)=>$p['purchase_count']>0)),'opportunities'=>count($latest->evidence['opportunities']),'product_affinities'=>count($latest->evidence['affinity']['products']),'frozen_predictions'=>$predictions->count(),'completed_windows'=>$predictions->whereNotNull('evaluated_at')->count(),'genuine_timing_outcomes'=>$predictions->filter(fn($p)=>$p->evaluation['timing_qualified']??false)->unique(fn($p)=>$p->entity_id.':'.$p->evaluation['subsequent_purchase']['source'])->count(),'champion'=>$policy?->settings['champion']??'interval_iqr_baseline','local_python'=>$latest->evidence['local_model']['state'],'health_codes'=>array_column($latest->evidence['health'],'code')];
    }$this->line(json_encode($rows,JSON_PRETTY_PRINT));
})->purpose('Read customer intelligence counts without changing any data');

// Advisory calculations only. No accounting or inventory posting.
\Illuminate\Support\Facades\Artisan::command('finance-intelligence:refresh',function(){ $this->info('Updated '.app(\App\Services\FinancialIntelligenceService::class)->scheduled().' company financial forecasts.'); });
\Illuminate\Support\Facades\Schedule::command('finance-intelligence:refresh')->hourly()->withoutOverlapping();
\Illuminate\Support\Facades\Artisan::command('finance-intelligence:status',function(){
    $rows=[];
    foreach(\App\Models\Company::orderBy('id')->cursor() as $company){
        $snapshots=\App\Models\FinancialIntelligenceSnapshot::withoutGlobalScopes()->where('company_id',$company->id)->whereNull('evidence->archived')->get();
        $latest=$snapshots->sortByDesc('id')->first();if(!$latest)continue;
        $policy=\App\Models\FinancialIntelligencePolicy::withoutGlobalScopes()->where('company_id',$company->id)->latest('id')->first();
        $rows[]=['company_id'=>$company->id,'snapshots'=>$snapshots->count(),'observations'=>\App\Models\FinancialIntelligenceObservation::withoutGlobalScopes()->where('company_id',$company->id)->whereNull('facts->archived')->count(),'cash_accounts'=>count($latest->evidence['cash']['accounts']),'open_receivables'=>count($latest->evidence['receivables']),'open_commitments'=>count($latest->evidence['commitments']),'historical_completed_customer_payments'=>array_sum(array_column(array_column($latest->evidence['customers'],'history'),'count')),'cash_windows_scored'=>$snapshots->sum(fn($s)=>count($s->evaluation['cash']??[])),'post_prediction_payment_outcomes'=>$snapshots->flatMap(fn($s)=>$s->evaluation['collections']??[])->unique('key')->count(),'champion'=>$policy?->settings['champion']??'due_date_baseline','local_python'=>$latest->evidence['local_model']['state'],'data_warnings'=>array_values(array_unique(array_column($latest->evidence['health'],'code')))];
    }
    $this->line(json_encode($rows,JSON_PRETTY_PRINT));
})->purpose('Read financial forecast evidence health without changing business data');

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
Artisan::command('aims:heartbeat {queue=scheduler}', function () { app(\App\Services\ProductionReadinessService::class)->heartbeat($this->argument('queue')); });
Schedule::command('aims:heartbeat scheduler')->everyMinute()->withoutOverlapping();

Artisan::command('analytics:setup',function(){\App\Services\AnalyticsPermissions::install();$this->info('Analytics permissions installed.');});
Artisan::command('analytics:capture',function(){$this->info(app(\App\Services\AnalyticsDataService::class)->scheduled().' analytics observations captured.');});
Schedule::command('analytics:capture')->dailyAt('23:55')->withoutOverlapping();
Artisan::command('intelligence:maintain',function(){$this->info(app(\App\Services\InventoryIntelligenceService::class)->scheduled().' model candidates prepared; observation and evaluation maintenance completed.');});
Schedule::command('intelligence:maintain')->hourly()->withoutOverlapping();
Artisan::command('decisions:refresh',function(){$this->info(app(\App\Services\EnterpriseDecisionService::class)->scheduled().' products evaluated for enterprise decisions.');});
Schedule::command('decisions:refresh')->everyMinute()->withoutOverlapping();
Artisan::command('logistics:refresh',function(){$this->info(app(\App\Services\ShipmentIntelligenceService::class)->scheduled().' shipments evaluated.');});
Schedule::command('logistics:refresh')->everyMinute()->withoutOverlapping();

Artisan::command('automations:setup', function () { \App\Services\AutomationPermissions::install(); $this->info('Automation permissions installed without resetting roles.'); });
Artisan::command('automations:tick', function () { app(\App\Services\AutomationScheduler::class)->tick(); $this->info('Automation sweep completed.'); });
Schedule::command('automations:tick')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:sync-expiry-alerts')
    ->dailyAt('06:00')
    ->withoutOverlapping();

Artisan::command('documents:expiry-alerts',function(){ $this->info(app(\App\Services\DocumentMaintenanceService::class)->expiryAlerts().' notifications created.'); });
Schedule::command('documents:expiry-alerts')->dailyAt('07:00')->withoutOverlapping();
Artisan::command('documents:setup', function () {
    $slugs = ['view','upload','update_metadata','new_version','archive','download','review','manage','confidential'];
    $permissions = collect($slugs)->mapWithKeys(function ($slug) {
        $permission = \App\Models\Permission::firstOrCreate(['slug'=>'documents.'.$slug], ['name'=>'Documents '.str_replace('_',' ',$slug),'group'=>'documents']);
        return [$slug => $permission->id];
    });
    foreach (['admin','manager','staff'] as $slug) {
        $role = \App\Models\Role::where('slug',$slug)->first();
        if (!$role) continue;
        $ids = $slug === 'staff' ? $permissions->only(['view','upload','download','update_metadata']) : $permissions;
        $role->permissions()->syncWithoutDetaching($ids->values()->all());
        \Illuminate\Support\Facades\Cache::forget('role_permissions:'.$slug);
    }
    $this->info('Document permissions added; existing permissions preserved.');
})->purpose('Enable Document Center without resetting existing role permissions');
Artisan::command('documents:verify {company}',function(){ $this->line(json_encode(app(\App\Services\DocumentMaintenanceService::class)->verifyCompany((int)$this->argument('company')),JSON_PRETTY_PRINT)); });
