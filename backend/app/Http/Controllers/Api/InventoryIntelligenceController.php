<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\InventoryIntelligenceService;
use Illuminate\Http\Request;
class InventoryIntelligenceController extends Controller {
    public function performance(Request $r,int $product){return app(\App\Services\IntelligencePerformanceService::class)->report($product,(int)$r->query('horizon',30),$r->query('model_version'));}
    public function model(int $model){app(InventoryIntelligenceService::class)->authorize();$m=\App\Models\InventoryForecastModel::where('domain','inventory_demand')->findOrFail($model);return $m->makeHidden('artifact');}
    public function alert(int $alert){app(InventoryIntelligenceService::class)->authorize();return \App\Models\InventoryIntelligenceAlert::findOrFail($alert);}
    public function promote(Request $r,int $model){return app(\App\Services\InventoryLearningService::class)->decide($model,'promote',$r->all());}
    public function rollback(Request $r,int $model){return app(\App\Services\InventoryLearningService::class)->decide($model,'rollback',$r->all());}
    public function observe(Request $r,int $product){
        $s=app(InventoryIntelligenceService::class);$s->authorize(true);$p=$s->product($product);
        $data=$r->validate(['from'=>'sometimes|date_format:Y-m-d','to'=>'sometimes|date_format:Y-m-d']);
        $count=app(\App\Services\IntelligenceObservationService::class)->collectProduct($p,$data['from']??today()->subDays(30)->toDateString(),$data['to']??today()->subDay()->toDateString());
        $perf=app(\App\Services\IntelligencePerformanceService::class);$evaluated=$perf->evaluate();$perf->monitor($p);$perf->outcomes($p);
        return ['captured'=>$count,'evaluated'=>$evaluated];
    }
    public function recommendation(InventoryIntelligenceService $s,int $recommendation){$s->authorize();$r=\App\Models\InventoryRecommendation::where('company_id',auth()->user()->company_id)->findOrFail($recommendation);return ['product_id'=>$r->product_id,'horizon'=>$r->prediction->value['horizon'],'planning'=>(bool)$r->planning_key,'warehouse_id'=>$r->warehouse_id];}
    public function index(Request $r,InventoryIntelligenceService $s){return $s->listing($r->only('q','horizon','risk','warehouse_id'));}
    public function show(Request $r,InventoryIntelligenceService $s,int $product){return $s->detail($product,(int)$r->query('horizon',30),$r->filled('supplier_id')?(int)$r->query('supplier_id'):null,$r->query('unit'));}
    public function train(InventoryIntelligenceService $s,int $product){try{return $s->train($product);}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){throw $e;}catch(\App\Exceptions\ForecastUnavailableException $e){return response()->json(['message'=>$e->getMessage(),'status'=>'unavailable'],503);}}
    public function feedback(Request $r,InventoryIntelligenceService $s,int $recommendation){return $s->feedback($recommendation,$r->all());}
    public function draft(Request $r,InventoryIntelligenceService $s,int $recommendation){return $s->draft($recommendation,$r->all());}
}
