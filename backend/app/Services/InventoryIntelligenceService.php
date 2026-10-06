<?php
namespace App\Services;
use App\Contracts\DemandForecastProvider;
use App\Models\{Product,AnalyticsSnapshot,AnalyticsPrediction,InventoryForecastModel,InventoryRecommendation,PurchaseRequest,Company,User};
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable as Date;

final class InventoryIntelligenceService {
    public function __construct(private DemandForecastProvider $provider,private InventoryIntelligenceDatasetAdapter $adapter,private InventoryIntelligencePlanner $planner){}
    public function authorize(bool $train=false):void {
        abort_unless(Auth::user()?->company_id,403);
        $p=app(PermissionService::class);
        foreach(['analytics.view','inventory.view',...($train?['analytics.ml_datasets','analytics.finance']:[])] as $key)abort_unless($p->roleHasPermission(Auth::user()->role,$key),403);
    }
    public function product(int $id):Product {$this->authorize();return Product::where('company_id',Auth::user()->company_id)->findOrFail($id);}
    public function listing(array $filters=[]):array {
        $this->authorize();$h=(int)($filters['horizon']??30);abort_unless(in_array($h,[7,30,90],true),422);
        $q=Product::where('lifecycle_status','active')->when($filters['q']??null,fn($q,$term)=>$q->where(fn($q)=>$q->where('name','like','%'.mb_substr($term,0,100).'%')->orWhere('sku','like','%'.mb_substr($term,0,100).'%')));
        if(!empty($filters['warehouse_id']))$q->whereHas('warehouseStock',fn($q)=>$q->where('warehouse_id',(int)$filters['warehouse_id'])->where('quantity','>',0));
        if(!empty($filters['risk']))$q->whereHas('intelligenceRecommendations',fn($q)=>$q->where('risk',$filters['risk'])->whereIn('status',['open','viewed'])->whereHas('prediction',fn($q)=>$q->where('valid_until','>',now())->where('value->horizon',$h)));
        $page=$q->orderBy('name')->paginate(25);
        $rows=collect($page->items())->map(function($product)use($h){
            $p=$this->latest($product->id,$h);$rec=$p?InventoryRecommendation::where('analytics_prediction_id',$p->id)->first():null;
            return ['id'=>$product->id,'name'=>$product->name,'sku'=>$product->sku,'unit'=>$product->unit,'prediction'=>$p,'recommendation'=>$this->safeRecommendation($rec),'url'=>'/inventory-intelligence?product='.$product->id];
        })->all();
        return ['rows'=>$rows,'total'=>$page->total(),'page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'horizon'=>$h,
            'warehouse_scope'=>'Warehouse filters locate products; forecasts are company-wide. Warehouse demand attribution is not inferred.',
            'scheduled_training'=>(bool)config('inventory_intelligence.scheduled_training'),'accuracy'=>$this->companyAccuracy($h)];
    }
    private function latest(int $id,int $h):?AnalyticsPrediction{return AnalyticsPrediction::where('entity_type','product')->where('entity_id',$id)->where('model_key','inventory-demand-v1')->where('value->horizon',$h)->latest('id')->first();}
    public function detail(int $id,int $h=30,?int $supplier=null,?string $unit=null):array {
        $product=$this->product($id);abort_unless(in_array($h,[7,30,90],true),422);
        $prediction=$this->latest($id,$h);$suppliers=$this->planner->suppliers($product);
        $current=$prediction?$this->planner->calculate($product,$prediction->value['daily'],$suppliers,$supplier,$unit):null;
        $rec=$prediction?InventoryRecommendation::where('analytics_prediction_id',$prediction->id)->first():null;
        $model=$prediction?InventoryForecastModel::where('version',$prediction->model_version)->first():null;
        if(!$this->canFinance()){
            if($current)$current=array_replace($current,['unit_price'=>null,'estimated_cost'=>null]);
            $suppliers=array_map(function($s){$s['purchase_price']=null;unset($s['performance']['commercial'],$s['performance']['delivery']['total_purchased_value'],$s['performance']['quality']['claim_value']);return $s;},$suppliers);
        }
        return ['product'=>$product->only(['id','name','sku','unit','min_quantity','safety_stock','replenishment_review_days']),
            'prediction'=>$prediction,'model'=>$model?->makeHidden(['artifact']),'recommendation'=>$this->safeRecommendation($rec),'current'=>$current,
            'history'=>$this->adapter->history($product,today()->subDay()->toDateString())['series'],
            'suppliers'=>$suppliers,'units'=>$product->units()->where('is_active',true)->get(['code','factor_to_base','conversion_mode']),
            'warehouses'=>$product->warehouseStock()->with('warehouse:id,name')->get(['warehouse_id','available_quantity','reserved_quantity']),
            'stale'=>$prediction?($prediction->valid_until?->isPast()||($prediction->value['unit']??null)!==$product->unit):false,
            'accuracy'=>$this->accuracy($id)];
    }
    private function canFinance():bool{return app(PermissionService::class)->roleHasPermission(Auth::user()->role,'analytics.finance');}
    private function safeRecommendation(?InventoryRecommendation $rec):?array {
        if(!$rec)return null;$data=$rec->toArray();
        if(!$this->canFinance())$data['explanation']=array_replace($data['explanation'],['unit_price'=>null,'estimated_cost'=>null]);
        return $data;
    }
    public function train(int $id):array {
        return app(InventoryLearningService::class)->prepare($id);
    }
    public function refreshProduction(int $id):array {
        $this->authorize(true);$product=$this->product($id);
        return Cache::lock('inventory-intelligence:'.$product->company_id.':'.$id,120)->block(2,function()use($product,$id){
            $cutoff=today()->subDay()->toDateString();
            $history=$this->adapter->history($product,$cutoff);
            $active=InventoryForecastModel::where('product_id',$id)->where('status','active')->where('feature_version',InventoryIntelligenceDatasetAdapter::VERSION)
                ->whereIn('analytics_dataset_id',\App\Models\AnalyticsDatasetRow::where('values->unit',$product->unit)->select('analytics_dataset_id'))->get()->keyBy('horizon');
            if($active->isEmpty())return ['status'=>'no_production_model'];
            if(AnalyticsPrediction::where('entity_id',$id)->where('entity_type','product')->whereIn('model_version',$active->pluck('version'))->where('value->cutoff',$cutoff)->where('value->unit',$product->unit)->where('valid_until','>',now())->count()>=$active->count())return ['status'=>'reused'];
            $incumbents=[];foreach($active as $h=>$m){
                if(!hash_equals($m->artifact_hash,self::artifactHash($m->artifact)))throw new \App\Exceptions\ForecastUnavailableException('Stored model integrity check failed.');
                $incumbents[(string)$h]=$m->artifact;
            }
            $result=$this->provider->predict($history,$incumbents);
            if($result['status']==='insufficient_data')return $result;
            $anchor=AnalyticsSnapshot::where('entity_type','product')->where('entity_id',$id)->where('warehouse_id',0)->latest('snapshot_date')->first();
            if(!$anchor){app(AnalyticsDataService::class)->capture();$anchor=AnalyticsSnapshot::where('entity_type','product')->where('entity_id',$id)->where('warehouse_id',0)->latest('snapshot_date')->firstOrFail();}
            $dataset=$this->adapter->freeze($product,$history,$anchor);
            $suppliers=$this->planner->suppliers($product);
            DB::transaction(function()use($product,$result,$active,$dataset,$anchor,$cutoff,$suppliers){
                Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                foreach($result['results'] as $r){
                    $h=(int)$r['horizon'];$previous=$this->latest($product->id,$h);
                    $model=$active->get($h);
                    if(!$model||$model->fresh()->status!=='active')continue;
                    if(AnalyticsPrediction::where('entity_id',$product->id)->where('entity_type','product')->where('model_version',$model->version)->where('value->cutoff',$cutoff)->where('valid_until','>',now())->exists())continue;
                    $explanation=$this->planner->calculate($product,$r['daily'],$suppliers);
                    $prediction=AnalyticsPrediction::create(['company_id'=>$product->company_id,'prediction_type'=>'inventory_demand','entity_type'=>'product','entity_id'=>$product->id,
                        'model_key'=>'inventory-demand-v1','model_version'=>$model->version,'input_feature_version'=>InventoryIntelligenceDatasetAdapter::VERSION,
                        'analytics_snapshot_id'=>$anchor->id,'value'=>['horizon'=>$h,'cutoff'=>$cutoff,'unit'=>$product->unit,'total'=>$r['total'],'daily'=>$r['daily'],
                            'dataset_version'=>$dataset->version,'quality'=>$result['quality'],'status'=>$r['status'],'range'=>null,'range_reason'=>$r['range_reason'],
                            'baseline_daily'=>$r['baseline_daily']??[],'training_cutoff'=>$model->training_cutoff->toDateString(),
                            'evaluation'=>$model->metrics,'comparison'=>$model->comparison,'retained_incumbent'=>true],'generated_at'=>now(),'valid_until'=>now()->addDay(),'confidence'=>null]);
                    $recommendation=InventoryRecommendation::create(['company_id'=>$product->company_id,'product_id'=>$product->id,'analytics_prediction_id'=>$prediction->id,'risk'=>$explanation['risk'],'explanation'=>$explanation]);
                    InventoryRecommendation::where('product_id',$product->id)->where('id','!=',$recommendation->id)->whereIn('status',['open','viewed'])
                        ->whereNull('planning_key')->whereHas('prediction',fn($q)=>$q->where('value->horizon',$h))->update(['status'=>'superseded']);
                    if($h===30){
                        app(BusinessEventService::class)->record('inventory_forecast.updated',$recommendation,$product->sku,['horizon'=>$h,'predicted_demand'=>$r['total'],'risk'=>$explanation['risk']], 'forecast:'.$prediction->id);
                        $old=$previous?InventoryRecommendation::where('analytics_prediction_id',$previous->id)->first():null;
                        if(in_array($explanation['risk'],['high','watch']) && $old?->risk!==$explanation['risk'])
                            app(BusinessEventService::class)->record('inventory_stockout_risk.detected',$recommendation,$product->sku,['risk'=>$explanation['risk'],'recommended_quantity'=>$explanation['base_quantity'],'stockout_date'=>$explanation['stockout_date']], 'risk:'.$prediction->id);
                    }
                }
            });
            app(AnalyticsDataService::class)->audit('inventory_intelligence.predicted',['product_id'=>$product->id,'dataset_version'=>$dataset->version]);
            return ['status'=>'ready','quality'=>$result['quality'],'detail'=>$this->detail($product->id)];
        });
    }
    public function feedback(int $id,array $input):array {
        $this->authorize();$rec=InventoryRecommendation::where('company_id',Auth::user()->company_id)->findOrFail($id);
        $data=validator($input,['action'=>'required|in:viewed,dismissed','note'=>'nullable|string|max:1000'])->validate();
        if(in_array($rec->status,['open','viewed']) && $rec->status!==$data['action']){
            $rec->update(['status'=>$data['action'],'viewed_at'=>now(),'feedback'=>['action'=>$data['action'],'note'=>$data['note']??null,'user_id'=>Auth::id(),'at'=>now()->toIso8601String()]]);
            app(AnalyticsDataService::class)->audit('inventory_intelligence.'.$data['action'],['recommendation_id'=>$rec->id,'note'=>$data['note']??null]);
        }
        return $this->safeRecommendation($rec);
    }
    public function draft(int $id,array $input):array {
        $this->authorize();abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,'procurement.manage'),403);
        $d=validator($input,['fingerprint'=>'required|string|size:64','supplier_id'=>'required|integer','unit'=>'required|string|max:30','quantity'=>'nullable|numeric|gt:0','note'=>'nullable|string|max:1000'])->validate();
        return DB::transaction(function()use($id,$d){
            $rec=InventoryRecommendation::where('company_id',Auth::user()->company_id)->lockForUpdate()->findOrFail($id);
            abort_if($rec->planning_key,409,'Use Inventory Planning to review and draft this recommendation.');
            if($rec->purchase_request_id)return ['purchase_request'=>PurchaseRequest::findOrFail($rec->purchase_request_id),'reused'=>true];
            abort_unless(in_array($rec->status,['open','viewed']),422,'This recommendation is no longer open.');
            $product=Product::whereKey($rec->product_id)->lockForUpdate()->firstOrFail();$prediction=$rec->prediction;
            $pending=InventoryRecommendation::where('product_id',$product->id)->whereNotNull('purchase_request_id')
                ->whereHas('purchaseRequest',fn($q)=>$q->whereIn('status',['draft','submitted','approved','sourcing']))->first();
            abort_if($pending,409,'An open purchase request already exists for this product recommendation. Review it in Procurement before creating another draft.');
            abort_if(!$prediction||$prediction->valid_until->isPast()||($prediction->value['unit']??null)!==$product->unit,409,'Forecast expired or unit changed. Refresh the forecast before preparing a request.');
            $current=$this->planner->calculate($product,$prediction->value['daily'],$this->planner->suppliers($product),(int)$d['supplier_id'],$d['unit']);
            abort_unless(hash_equals($current['fingerprint'],$d['fingerprint']),409,'Stock, incoming orders or supplier terms changed. Review the refreshed recommendation before creating a request.');
            abort_unless($current['coverage_supported'] && $current['quantity']>0,422,'The forecast does not support a replenishment request for this lead time, or no purchase is needed.');
            $quantity=(float)($d['quantity']??$current['quantity']);$converted=app(UnitConversionService::class)->resolve($product,$quantity,$d['unit']);
            $supplier=$product->supplierCatalogue()->where('supplier_id',$d['supplier_id'])->where('is_active',true)->firstOrFail();
            $base=(float)$converted['base_quantity'];$pack=max(.001,(float)$supplier->pack_size);
            if($base+.0005<(float)$supplier->minimum_order_quantity || abs($base/$pack-round($base/$pack))>.00001)throw ValidationException::withMessages(['quantity'=>'Quantity must respect the current supplier minimum and pack multiple.']);
            $request=app(ProcurementService::class)->createRequest(['requested_at'=>today()->toDateString(),'required_by'=>$current['expected_date'],'currency'=>$current['currency'],
                'notes'=>'Inventory Intelligence recommendation #'.$rec->id.'; model '.$prediction->model_version.'; preferred supplier '.$current['supplier_name'].'. Human review required. '.($d['note']??''),
                'items'=>[['product_id'=>$product->id,'description'=>$product->name,'unit'=>$d['unit'],'quantity'=>$quantity,'estimated_unit_price'=>$current['unit_price']??0]]]);
            $rec->update(['status'=>abs($quantity-$current['quantity'])>.0005?'adjusted':'accepted','purchase_request_id'=>$request->id,
                'feedback'=>['user_id'=>Auth::id(),'supplier_id'=>$supplier->supplier_id,'quantity'=>$quantity,'base_quantity'=>$base,'recommended_base_quantity'=>$current['base_quantity'],'unit'=>$d['unit'],'note'=>$d['note']??null,'at'=>now()->toIso8601String()]]);
            app(AnalyticsDataService::class)->audit('inventory_intelligence.'.$rec->status,['recommendation_id'=>$rec->id,'purchase_request_id'=>$request->id,'quantity'=>$quantity,'unit'=>$d['unit']]);
            return ['purchase_request'=>$request,'url'=>'/procurement?view=requests&request='.$request->id,'reused'=>false];
        });
    }
    public function accuracy(int $productId):array {
        $this->product($productId);
        $rows=AnalyticsPrediction::where('entity_type','product')->where('entity_id',$productId)->where('model_key','inventory-demand-v1')->whereNotNull('evaluated_at')->latest()->limit(30)->get();
        return ['outcomes'=>$rows->map(fn($p)=>['id'=>$p->id,'horizon'=>$p->value['horizon'],'evaluation'=>$p->evaluation,'actual'=>$p->actual_value,'model_version'=>$p->model_version])->all(),
            'summary'=>['evaluated'=> $rows->where('evaluation.eligible',true)->count(),'excluded'=>$rows->where('evaluation.eligible',false)->count()]];
    }
    public function companyAccuracy(int $horizon=30):array {
        $this->authorize();
        $rows=AnalyticsPrediction::where('model_key','inventory-demand-v1')->where('value->horizon',$horizon)
            ->whereNotNull('evaluated_at')->where('generated_at','>=',now()->subYear())->orderBy('entity_id')->orderBy('generated_at')->get();
        $groups=[];$ends=[];$excluded=0;
        foreach($rows as $p){
            $daily=$p->value['daily']??[];$first=$daily[0]['date']??null;$last=last($daily)['date']??null;
            // Never give frequently refreshed, overlapping forecasts extra weight.
            if(($p->evaluation['version']??1)!==2||!($p->evaluation['eligible']??false)||!$first||!$last||($ends[$p->entity_id]??'')>=$first){$excluded++;continue;}
            $ends[$p->entity_id]=$last;$unit=$p->value['unit'];$n=(int)($p->evaluation['observed_days']??0);if(!$n)continue;
            $g=$groups[$unit]??['unit'=>$unit,'horizon'=>$horizon,'observations'=>0,'forecasts'=>0,'products'=>[],'absolute_error'=>0,'signed_error'=>0,'actual'=>0];
            $g['observations']+=$n;$g['forecasts']++;$g['products'][$p->entity_id]=true;
            $g['absolute_error']+=$p->evaluation['mae']*$n;$g['signed_error']+=$p->evaluation['bias']*$n;$g['actual']+=$p->actual_value['observed_demand'];$groups[$unit]=$g;
        }
        return ['groups'=>array_values(array_map(fn($g)=>['unit'=>$g['unit'],'horizon'=>$g['horizon'],'products'=>count($g['products']),'forecasts'=>$g['forecasts'],'observations'=>$g['observations'],
            'mae'=>$g['absolute_error']/$g['observations'],'wape'=>$g['actual']>0?100*$g['absolute_error']/$g['actual']:null,'bias'=>$g['signed_error']/$g['observations']],$groups)),
            'excluded'=>$excluded,'scope'=>'Last year, complete non-overlapping outcome windows only; units and horizons are never mixed.'];
    }
    public function evaluate():int {
        return app(IntelligencePerformanceService::class)->evaluate();
    }
    public static function artifactHash(array $artifact):string {
        $sort=function(array $x)use(&$sort){if(!array_is_list($x))ksort($x);foreach($x as &$v)if(is_array($v))$v=$sort($v);return $x;};
        return hash('sha256',json_encode($sort($artifact),JSON_THROW_ON_ERROR));
    }
    public function scheduled():int {
        return app(IntelligenceMaintenanceService::class)->run();
    }
}
