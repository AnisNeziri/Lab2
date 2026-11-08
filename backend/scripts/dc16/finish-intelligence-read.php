<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\{DB,Auth};
Auth::setUser(App\Models\User::withoutGlobalScopes()->where('company_id',2)->where('role','admin')->firstOrFail());
$path=$dc16Root.'/storage/evidence/intelligence-runs.json';$e=json_decode(file_get_contents($path),true);
foreach(['optimizer'=>['supply_optimization_plans',App\Services\SupplyOptimizerService::class],'simulation'=>['strategic_simulations',App\Services\StrategicSimulationService::class]] as $kind=>[$table,$service]){
 if(($e['results'][$kind]['error']??'')!=='Call to undefined method '.$service.'::show()')continue;
 $id=DB::table($table)->where('company_id',2)->latest('id')->value('id');
 $prior=$e['results'][$kind];$e['results'][$kind]=['output'=>app($service)->get($id),'harness_note'=>'Result accessor corrected from show to get; existing run read without regeneration.','prior_harness_error'=>$prior['error'],'qualification'=>'Current advisory run, not historical forecast or business commitment'];
}
dc16Evidence('intelligence-runs',$e);echo "Existing intelligence run outputs recovered without re-execution.\n";
