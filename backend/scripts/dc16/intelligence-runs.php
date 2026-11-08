<?php
require __DIR__.'/api.php';
use Illuminate\Support\Facades\{Auth,DB};
$file=$dc16Root.'/storage/evidence/intelligence-runs.json';if(is_file($file)){echo "Intelligence run evidence retained.\n";exit;}
$token=dc16Login('primary');$before=[];foreach(['stock_movements','journal_entries','purchase_orders','purchase_requests','sales_orders'] as $t)$before[$t]=DB::table($t)->count();
$results=[];
try{$results['finance_refresh']=dc16Request('POST','financial-intelligence/refresh',[],$token,[],[200,202,422]);}catch(Throwable $e){$results['finance_refresh']=['status'=>'BLOCKED','error'=>$e->getMessage()];}
foreach(['optimizer','simulation'] as $kind){
 try{
  if($kind==='optimizer'){$s=app(App\Services\SupplyOptimizerService::class);$r=$s->submit(['horizon'=>30,'product_ids'=>[4],'commitment_limit'=>'1000.00']);$s->run($r['id']);$output=$s->get($r['id']);}
  else{$s=app(App\Services\StrategicSimulationService::class);$r=$s->submit(['name'=>'DC16 bounded demand sensitivity','horizon'=>30,'scope'=>['product_ids'=>[4]],'assumptions'=>[['type'=>'demand','action'=>'percent','value'=>10]],'optimize'=>false]);$s->run($r['id']);$output=$s->get($r['id']);}
  $results[$kind]=['output'=>$output,'qualification'=>'Current frozen advisory run; not relabeled as a historical forecast or executed procurement action.'];
 }catch(Throwable $e){$results[$kind]=['status'=>'BLOCKED','exception'=>get_class($e),'error'=>$e->getMessage()];}
 dc16Evidence('intelligence-runs',['results'=>$results,'before'=>$before,'in_progress'=>true]);
}
$after=[];foreach(array_keys($before) as $t)$after[$t]=DB::table($t)->count();
dc16Evidence('intelligence-runs',['results'=>$results,'business_before'=>$before,'business_after'=>$after,'business_unchanged'=>$before===$after,'historical_backtests'=>'temporal-backtests.json']);echo "Bounded current V7/V11/V12 runs recorded with no business commitment.\n";

