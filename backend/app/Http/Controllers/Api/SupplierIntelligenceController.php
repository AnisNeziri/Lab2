<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\{SupplierIntelligenceService,SupplierLearningService};
use Illuminate\Http\Request;
class SupplierIntelligenceController extends Controller {
 public function show(Request $r,int $supplier){$input=$r->validate(['product_id'=>'sometimes|integer|min:1','category_id'=>'sometimes|integer|min:1','origin'=>'sometimes|string|max:100','destination'=>'sometimes|string|max:100','mode'=>'sometimes|string|max:30']);return app(SupplierIntelligenceService::class)->report($supplier,$input);}
 public function refresh(int $supplier){return app(SupplierIntelligenceService::class)->refresh($supplier);}
 public function train(int $supplier){try{return app(SupplierLearningService::class)->train($supplier);}catch(\App\Exceptions\ForecastUnavailableException $e){return response()->json(['message'=>$e->getMessage(),'status'=>'unavailable'],503);}}
 public function feedback(Request $r,int $risk){return app(SupplierIntelligenceService::class)->feedback($risk,$r->all());}
 public function promote(Request $r,int $model){return app(SupplierLearningService::class)->decide($model,'promote',$r->all());}
 public function rollback(Request $r,int $model){return app(SupplierLearningService::class)->decide($model,'rollback',$r->all());}
}
