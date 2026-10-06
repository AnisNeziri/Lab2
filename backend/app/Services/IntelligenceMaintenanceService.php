<?php
namespace App\Services;
use App\Models\{Company,User,Product};
use Illuminate\Support\Facades\{Auth,Cache};
final class IntelligenceMaintenanceService {
    public function run():int {
        $previous=Auth::user();$prepared=0;
        try{foreach(Company::orderBy('id')->cursor() as $company){
            $operator=User::withoutGlobalScopes()->where('company_id',$company->id)->where('is_active',true)->whereIn('role',['admin','manager'])->first();if(!$operator)continue;Auth::setUser($operator);
            try{Cache::lock('intelligence-maintain-v2:'.$company->id,600)->get(function()use($company,&$prepared){
                $start=microtime(true);$v1=app(InventoryIntelligenceService::class);$v1->authorize(true);$performance=app(IntelligencePerformanceService::class);
                $budget=config('inventory_intelligence.maintenance_max_seconds');
                app(IntelligenceObservationService::class)->collectBatch($start+$budget/3);$performance->evaluate($start+2*$budget/3);$attempted=0;$error=null;
                foreach(Product::where('lifecycle_status','active')->orderBy('id')->cursor() as $p){
                    if(microtime(true)-$start>config('inventory_intelligence.maintenance_max_seconds')||$attempted>=config('inventory_intelligence.batch_limit'))break;
                    $key='intelligence-daily-v2:'.$company->id.':'.$p->id.':'.today()->toDateString();if(Cache::has($key))continue;
                    try{$performance->monitor($p);$performance->outcomes($p);$learning=app(InventoryLearningService::class);
                        if(config('inventory_intelligence.scheduled_training')&&$learning->shouldRetrain($p)['justified']){$r=$learning->prepare($p->id);if($r['status']==='candidate_ready')$prepared++;}
                        else $v1->refreshProduction($p->id);
                        Cache::put($key,true,86400);
                    }catch(\Throwable $e){$error=class_basename($e);Cache::put($key,true,3600);} $attempted++;
                }
                try{app(SupplierIntelligenceService::class)->maintain(microtime(true)+15);}catch(\Throwable $e){$error??=class_basename($e);}
                try{app(InventoryPlanningService::class)->maintain(microtime(true)+10);}catch(\Throwable $e){$error??=class_basename($e);}
                app(MaintenanceHealthService::class)->record($company->id,'inventory_intelligence',$error);
            });}catch(\Throwable $e){app(MaintenanceHealthService::class)->record($company->id,'inventory_intelligence',class_basename($e));}
        }}finally{$previous?Auth::setUser($previous):Auth::forgetUser();}return $prepared;
    }
}
