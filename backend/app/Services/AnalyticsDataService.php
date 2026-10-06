<?php
namespace App\Services;
use App\Models\{AnalyticsSnapshot,AnalyticsDataset,AnalyticsDatasetRow,AnalyticsIssue,Company,User,Product,WarehouseStock,PurchaseOrder,Shipment,SalesOrder,Invoice,DailySaleItem,ActivityLog};
use Illuminate\Support\Facades\{Auth,DB,Cache};
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable as Date;

class AnalyticsDataService {
    public function featureSnapshot(array $input):array {
        $this->authorizeDatasets();$d=validator($input,['entity_type'=>'required|in:product,customer,supplier','entity_id'=>'required|integer','date'=>'required|date_format:Y-m-d'])->validate();
        $s=AnalyticsSnapshot::where('entity_type',$d['entity_type'])->where('entity_id',$d['entity_id'])->whereDate('snapshot_date',$d['date'])->where('warehouse_id',0)->firstOrFail();
        return ['snapshot'=>$s->toArray(),'features'=>AnalyticsFeatureRegistry::features($d['entity_type'],$s->facts),'definitions'=>AnalyticsFeatureRegistry::definitions()];
    }
    public function authorizeDatasets():void {
        $s=app(AnalyticsService::class);$s->authorize('overview');abort_unless($s->can('analytics.ml_datasets')&&$s->can('analytics.finance'),403);
    }
    public function capture(?string $date=null):array {
        $this->authorizeDatasets();$date??=today()->toDateString();
        if($date!==today()->toDateString())throw ValidationException::withMessages(['date'=>'Past state cannot be reconstructed from current balances. Only capture today; historical snapshots remain immutable.']);
        $company=Auth::user()->company_id;
        return Cache::lock('analytics-capture:'.$company,300)->block(5,function()use($company,$date){
            if(AnalyticsSnapshot::where('entity_type','backlog')->whereDate('snapshot_date',$date)->exists())return ['date'=>$date,'created'=>0,'reused'=>true];
            return DB::transaction(function()use($company,$date){
                $engine=app(AnalyticsService::class);$count=0;$observations=[];
                $save=function($type,$id,$facts,$warehouse=0)use(&$observations){
                    // Keep facts compact: names, URLs and presentation metadata are not training features.
                    $observations[]=[$type,$id,array_diff_key($facts,array_flip(['name','url','category'])),$warehouse];
                };
                $suppliers=collect($engine->supplierRows())->keyBy('id');foreach($suppliers as $s)$save('supplier',$s['id'],$s);
                foreach($engine->productRows() as $p){$s=$suppliers->get($p['supplier_id']);$p['supplier_average_lead_time']=$s['average_lead_time']??null;$p['supplier_lead_time_variance']=$s['lead_time_variance']??null;$save('product',$p['id'],$p);}
                WarehouseStock::with('product:id,unit,weighted_average_cost')->get()->groupBy(fn($s)=>$s->product_id.':'.$s->warehouse_id)->each(function($rows)use($save){$first=$rows->first();$save('inventory',$first->product_id,['unit'=>$first->product?->unit,'on_hand'=>$rows->sum('quantity'),'available'=>$rows->sum('available_quantity'),'reserved'=>$rows->sum('reserved_quantity'),'incoming'=>null,'stock_value'=>$first->product?->weighted_average_cost===null?null:\App\Support\Money::multiply((string)$rows->sum('quantity'),$first->product->weighted_average_cost)],$first->warehouse_id);});
                foreach($engine->customers(AnalyticsPeriod::resolve(['period'=>'90d']))['rows'] as $c)$save('customer',$c['id'],$c);
                $open=SalesOrder::with('items')->whereNotIn('status',['delivered','cancelled'])->get();
                $unfulfilled='0.00';foreach($open->where('currency',\App\Support\CompanyCurrency::current()) as $order)foreach($order->items as $item){
                    $factor=(float)($item->conversion_factor??1);if($factor<=0)continue;
                    $line=\App\Support\Money::multiply(\App\Support\Money::normalizeDecimal(max(0,(float)$item->base_quantity-(float)$item->dispatched_quantity)/$factor,3),$item->unit_price);
                    $unfulfilled=\App\Support\Money::add($unfulfilled,$line);
                }
                $save('backlog',0,['open_orders'=>$open->count(),'backordered_orders'=>$open->filter(fn($o)=>$o->items->contains(fn($i)=>(float)$i->base_quantity>(float)$i->reserved_quantity+(float)$i->dispatched_quantity))->count(),'unfulfilled_value'=>$unfulfilled]);
                // Timestamp the completed observation, never an earlier time than the facts read.
                $observed=now();foreach($observations as [$type,$id,$facts,$warehouse]){
                    $s=AnalyticsSnapshot::firstOrCreate(['company_id'=>$company,'snapshot_date'=>$date,'entity_type'=>$type,'entity_id'=>$id,'warehouse_id'=>$warehouse],['feature_version'=>AnalyticsFeatureRegistry::VERSION,'observed_at'=>$observed,'facts'=>$facts]);
                    if($s->wasRecentlyCreated)$count++;
                }
                $this->refreshIssues();
                $this->audit('analytics.snapshot.captured',['date'=>$date,'rows'=>$count]);
                return ['date'=>$date,'created'=>$count,'reused'=>false,'observed_at'=>$observed->toIso8601String()];
            });
        });
    }
    /** Run daily in local Laravel or the desktop maintenance process. No past backfill. */
    public function scheduled():int {
        $previous=Auth::user();$count=0;
        try{Company::query()->orderBy('id')->each(function($company)use(&$count){
            $user=User::withoutGlobalScopes()->where('company_id',$company->id)->where('is_active',true)->whereIn('role',['admin','manager'])->orderBy('id')->first();
            $health=app(MaintenanceHealthService::class);
            if(!$user){$health->record($company->id,'analytics_capture','no_active_operator');return;}Auth::setUser($user);
            try{
                $result=$this->capture();$count+=$result['created'];
                $health->record($company->id,'analytics_capture');
            }catch(\Throwable $e){
                $health->record($company->id,'analytics_capture',class_basename($e));
                \Illuminate\Support\Facades\Log::warning('Analytics snapshot skipped',['company_id'=>$company->id,'error_class'=>class_basename($e)]);
            }
        });}finally{$previous?Auth::setUser($previous):Auth::forgetUser();}return $count;
    }
    public function qualityReport():array {
        $issues=[];$add=function($code,$type,$id,$url,$severity='warning',$details=[])use(&$issues){$issues[]=['code'=>$code,'entity_type'=>$type,'entity_id'=>$id,'name'=>$code,'severity'=>$severity,'url'=>$url,'details'=>$details];};
        foreach(PurchaseOrder::with('goodsReceipts')->whereNotIn('status',['draft','cancelled'])->get() as $o){
            $actual=$o->goodsReceipts->max('received_at')??$o->received_at;
            if(in_array($o->status,['received','completed'])&&!$actual)$add('missing_receipt_date','PurchaseOrder',$o->id,'/purchase-orders?po='.$o->id);
            if($actual&&$o->ordered_at&&AnalyticsService::days($o->ordered_at,$actual)<0)$add('receipt_before_order','PurchaseOrder',$o->id,'/purchase-orders?po='.$o->id,'problem');
            if(!$o->supplier_id)$add('missing_supplier','PurchaseOrder',$o->id,'/purchase-orders?po='.$o->id,'problem');
        }
        foreach(Shipment::whereNotNull('arrival_date')->get() as $s)if(!$s->departed_at||AnalyticsService::days($s->departed_at,$s->arrival_date)<0)$add('invalid_transit_dates','Shipment',$s->id,'/shipments/my-shipments?shipment='.$s->id,'problem');
        foreach(SalesOrder::with('dispatches')->where('status','delivered')->get() as $o){$dispatch=$o->dispatches->min('dispatched_at');
            if(!$o->completed_at||!$dispatch||AnalyticsService::days($dispatch,$o->completed_at)<0||AnalyticsService::days($o->created_at,$dispatch)<0)$add('invalid_order_sequence','SalesOrder',$o->id,'/fulfillment?order='.$o->id,'problem');
        }
        foreach(DailySaleItem::whereDoesntHave('product')->get() as $i)$add('missing_product','DailySaleItem',$i->id,'/daily-sales','problem');
        foreach(DailySaleItem::where(fn($q)=>$q->where('quantity','<=',0)->orWhere('unit_price','<',0)->orWhere('line_total','<',0))->get() as $i)$add('invalid_sale_values','DailySaleItem',$i->id,'/daily-sales','problem');
        foreach(Product::where(fn($q)=>$q->where('purchase_price','<',0)->orWhere('selling_price','<',0))->get() as $product)$add('invalid_product_prices','Product',$product->id,'/products?product='.$product->id,'problem');
        foreach(Invoice::whereNotNull('issued_at')->whereNull('voided_at')->where('currency','!=',\App\Support\CompanyCurrency::current())->get() as $i)$add('currency_excluded','Invoice',$i->id,'/invoices?invoice='.$i->id,'warning');
        $duplicates=Invoice::whereNotNull('issued_at')->whereNull('voided_at')->where('document_type','!=','credit_note')->whereNotNull('daily_sale_id')->get()->groupBy('daily_sale_id')->filter(fn($g)=>$g->count()>1);
        foreach($duplicates as $id=>$g)$add('duplicate_sale_documents','DailySale',$id,'/daily-sales','problem',['invoice_ids'=>$g->pluck('id')->all()]);
        foreach(Product::where('quantity','<',0)->get() as $p)$add('negative_stock','Product',$p->id,'/products?product='.$p->id,'problem');
        $snapshots=AnalyticsSnapshot::where('entity_type','product')->where('warehouse_id',0)->select('snapshot_date')->distinct()->count();
        if($snapshots<90)$add('insufficient_history','Company',Auth::user()->company_id,'/analytics?tab=inventory','warning',['captured_days'=>$snapshots,'required_days'=>90]);
        return ['metrics'=>['problems'=>collect($issues)->where('severity','problem')->count(),'warnings'=>collect($issues)->where('severity','warning')->count(),'snapshot_days'=>$snapshots],
            'rows'=>$issues,'status'=>collect($issues)->contains('severity','problem')?'problem':($issues?'warning':'healthy')];
    }
    public function refreshIssues():void {
        $keys=[];
        foreach($this->qualityReport()['rows'] as $row){$key=hash('sha256',$row['code'].':'.$row['entity_type'].':'.$row['entity_id']);$keys[]=$key;
            $old=AnalyticsIssue::where('issue_key',$key)->first();$new=!$old || $old->resolved_at;
            $issue=AnalyticsIssue::updateOrCreate(['company_id'=>Auth::user()->company_id,'issue_key'=>$key],['code'=>$row['code'],'severity'=>$row['severity'],'entity_type'=>$row['entity_type'],'entity_id'=>$row['entity_id'],'details'=>['url'=>$row['url']]+$row['details'],'detected_at'=>$new?now():$old->detected_at,'resolved_at'=>null]);
            if($new&&$row['severity']==='problem')app(BusinessEventService::class)->record('analytics.data_quality_problem',$issue,$row['code'],['code'=>$row['code'],'severity'=>$row['severity']],'analytics-issue:'.$issue->id.':'.$issue->detected_at->format('YmdHisu'));
        }
        AnalyticsIssue::whereNull('resolved_at')->whereNotIn('issue_key',$keys)->update(['resolved_at'=>now()]);
    }
    public function build(array $input):AnalyticsDataset {
        $this->authorizeDatasets();$p=AnalyticsPeriod::resolve(['period'=>'custom']+$input);
        $snapshots=AnalyticsSnapshot::where('entity_type','product')->where('warehouse_id',0)->whereDate('snapshot_date','>=',$p['from'])->whereDate('snapshot_date','<=',$p['to'])->orderBy('snapshot_date')->orderBy('entity_id')->limit(10001)->get();
        if($snapshots->isEmpty())throw ValidationException::withMessages(['range'=>'No captured product observations exist in this range. Capture data first; historical stock is never fabricated.']);
        if($snapshots->count()>10000)throw ValidationException::withMessages(['range'=>'Select a smaller range (maximum 10,000 rows).']);
        $targets=app(AnalyticsSalesLedger::class)->rows(Date::parse($p['from'])->addDay()->toDateString(),Date::parse($p['to'])->addDays(30)->min(today())->toDateString())->groupBy('product_id');$rows=[];$labelled=0;
        $units=Product::whereIn('id',$snapshots->pluck('entity_id')->unique())->pluck('unit','id');
        foreach($snapshots as $s){$start=Date::parse($s->snapshot_date)->addDay();$end=$start->addDays(29);$unitMatches=$units->get($s->entity_id)===($s->facts['unit']??null);$mature=$unitMatches&&$end->endOfDay()->lt(now());$target=null;
            if($mature){$target=round($targets->get($s->entity_id,collect())->where('return',false)->where('date','>=',$start->toDateString())->where('date','<=',$end->toDateString())->sum('quantity'),3);$labelled++;}
            $rows[]=['snapshot'=>$s->id,'values'=>['product_id'=>$s->entity_id,'date'=>$s->snapshot_date->toDateString(),'observed_at'=>$s->observed_at->toIso8601String(),'feature_version'=>$s->feature_version,'unit'=>$s->facts['unit']??null,
                'day_of_week'=>$s->snapshot_date->dayOfWeekIso,'month'=>$s->snapshot_date->month]+AnalyticsFeatureRegistry::features('product',$s->facts)+['target_demand_next_30d'=>$target,'target_from'=>$start->toDateString(),'target_to'=>$end->toDateString(),'label_status'=>!$unitMatches?'unit_unverifiable':($mature?'observed':'pending')]];
        }
        return DB::transaction(function()use($p,$rows,$labelled){
            $d=AnalyticsDataset::create(['company_id'=>Auth::user()->company_id,'name'=>'demand_forecasting_v1','version'=>(string)Str::uuid(),'date_from'=>$p['from'],'date_to'=>$p['to'],'feature_definitions'=>AnalyticsFeatureRegistry::definitions(),'row_count'=>count($rows),'labelled_count'=>$labelled,'quality_status'=>$labelled===count($rows)?'labelled_review_required':'waiting_for_outcomes','created_by'=>Auth::id()]);
            foreach($rows as $row)AnalyticsDatasetRow::create(['company_id'=>$d->company_id,'analytics_dataset_id'=>$d->id,'analytics_snapshot_id'=>$row['snapshot'],'values'=>$row['values']]);
            $this->audit('analytics.dataset.created',['dataset_id'=>$d->id,'version'=>$d->version,'rows'=>$d->row_count]);return $d;
        });
    }
    public function audit(string $action,array $details):void {ActivityLog::create(['company_id'=>Auth::user()->company_id,'user_id'=>Auth::id(),'action'=>$action,'entity'=>'Analytics','description'=>$action,'new_value'=>$details]);}
}
