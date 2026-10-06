<?php
namespace App\Services;
use App\Models\{Product,WarehouseStock,PurchaseOrder,Supplier,Customer,SalesOrder,Shipment,ShipmentContainer,LandedCost,QualityInspection,QualityDefect,SupplierClaim,PurchaseRequest,Rfq,ProcurementAward,ProductSupplierPriceHistory,Invoice,AnalyticsSnapshot};
use App\Support\{Money,CompanyCurrency};
use Carbon\CarbonImmutable as Date;
use Illuminate\Support\Facades\{Auth,Cache};
use Illuminate\Support\Collection;

class AnalyticsService {
    public const AREAS=['overview'=>null,'sales'=>'daily_sales.manage','inventory'=>'inventory.view','procurement'=>'procurement.view','suppliers'=>'suppliers.manage','customers'=>'debts.view','orders'=>'fulfillment.view','logistics'=>'shipments.view','quality'=>'quality.view','finance'=>'analytics.finance','data_quality'=>'analytics.data_quality'];
    public function can(string $permission):bool{return app(PermissionService::class)->roleHasPermission(Auth::user()->role,$permission);}
    public function authorize(string $area):void {
        abort_unless(Auth::user()?->company_id,403,'Analytics requires a company context.');
        abort_unless(array_key_exists($area,self::AREAS)&&$this->can('analytics.view'),403);
        if(self::AREAS[$area])abort_unless($this->can(self::AREAS[$area]),403);
        if(in_array($area,['sales','customers','procurement','suppliers','finance','data_quality']))abort_unless($this->can('analytics.finance'),403);
        if($area==='finance')abort_unless($this->can('accounting.reports.view'),403);
    }
    public function productPerformance(array $input):array {
        $data=$this->workspace('inventory',$input);
        if(isset($input['product_id'])){$data['rows']=array_values(array_filter($data['rows'],fn($r)=>(int)$r['id']===(int)$input['product_id']));abort_if(!$data['rows'],404);}
        else{$data['total_rows']=count($data['rows']);$data['rows']=array_slice($data['rows'],0,50);}
        return $data;
    }
    public function workspace(string $area,array $input=[]):array {
        $this->authorize($area);$p=AnalyticsPeriod::resolve($input);
        $key='analytics:v1:'.Auth::user()->company_id.':'.Auth::user()->role.':'.sha1(json_encode([$area,$p,app(PermissionService::class)->forRole(Auth::user()->role)]));
        return Cache::remember($key,60,function()use($area,$p){
            $data=$this->calculate($area,$p);
            if(in_array($area,['procurement','orders','logistics','quality','finance'])){
                $previous=$this->calculate($area,array_replace($p,['from'=>$p['previous_from'],'to'=>$p['previous_to']]))['metrics'];
                foreach($data['metrics'] as $metric=>$value)$data['comparison'][$metric]=is_numeric($value)&&is_numeric($previous[$metric]??null)&&(float)$previous[$metric]!==0.0?round(100*((float)$value-(float)$previous[$metric])/abs((float)$previous[$metric]),2):null;
            }
            return ['period'=>$p,'currency'=>CompanyCurrency::current(),'generated_at'=>now()->toIso8601String(),'area'=>$area]+$data;
        });
    }
    private function calculate(string $area,array $p):array {
        if($area==='overview'){
            $metrics=[];$rows=[];$comparison=[];
            if($this->can('daily_sales.manage')&&$this->can('analytics.finance')){
                $sales=$this->sales($p);$metrics+=$sales['metrics'];$comparison=$sales['comparison'];$rows=collect($sales['rows'])->sortByDesc('revenue')->take(5)->values()->all();
            }
            if($this->can('inventory.view'))$metrics['products']=Product::count();
            if($this->can('fulfillment.view'))$metrics['open_orders']=SalesOrder::whereNotIn('status',['delivered','cancelled'])->count();
            if($this->can('debts.view')&&$this->can('analytics.finance'))$metrics['outstanding']=AnalyticsSalesLedger::sum(Customer::get(['current_debt']),'current_debt');
            if($this->can('analytics.data_quality')&&$this->can('analytics.finance'))$metrics['problems']=\App\Models\AnalyticsIssue::whereNull('resolved_at')->where('severity','problem')->count();
            return ['metrics'=>array_intersect_key($metrics,array_flip(['revenue','sales_count','gross_profit','products','open_orders','outstanding','problems'])),'comparison'=>$comparison,'rows'=>$rows,'scope'=>'balances_current'];
        }
        if($area==='sales')return $this->sales($p);
        if($area==='inventory')return $this->inventory($p);
        if($area==='procurement')return $this->procurement($p);
        if($area==='suppliers')return ['metrics'=>['suppliers'=>Supplier::count()],'rows'=>$this->supplierRows(),'scope'=>'current'];
        if($area==='customers')return $this->customers($p);
        if($area==='orders')return $this->orders($p);
        if($area==='logistics')return $this->logistics($p);
        if($area==='quality')return $this->quality($p);
        if($area==='finance'){
            $report=app(AccountingService::class)->profitAndLoss($p['from'],$p['to']);
            $balances=app(AccountingService::class)->overview($p['from'],$p['to']);
            return ['metrics'=>array_intersect_key($report,array_flip(['revenue','cost_of_goods_sold','gross_profit','operating_expenses','net_profit']))+['gross_margin'=>self::percent($report['gross_profit'],$report['revenue'])]+array_intersect_key($balances,array_flip(['cash','accounts_receivable','accounts_payable'])),
                'rows'=>collect($report['accounts'])->map(fn($a)=>['id'=>$a['id']??$a['code'],'name'=>$a['name'],'code'=>$a['code'],'balance'=>$a['closing_balance'],'url'=>'/accounting'])->values()->all(),'scope'=>'accounting'];
        }
        return app(AnalyticsDataService::class)->qualityReport();
    }
    public function sales(array $p):array {
        $ledger=app(AnalyticsSalesLedger::class);$rows=$ledger->rows($p['from'],$p['to']);$current=$ledger->summary($rows);$previous=$ledger->summary($ledger->rows($p['previous_from'],$p['previous_to']));
        $comparison=[];foreach($current as $k=>$v)$comparison[$k]=is_numeric($v)&&is_numeric($previous[$k]??null)&&((float)$previous[$k])!==0.0?round(100*((float)$v-(float)$previous[$k])/abs((float)$previous[$k]),2):null;
        $group=fn($field)=>$rows->groupBy($field)->map(fn($items,$key)=>['name'=>(string)$key,'revenue'=>AnalyticsSalesLedger::sum($items,'revenue'),'sales_count'=>$items->pluck('sale_key')->unique()->count()])->values()->all();
        return ['metrics'=>$current,'comparison'=>$comparison,'trend'=>$rows->groupBy('date')->map(fn($items,$date)=>['date'=>$date,'revenue'=>AnalyticsSalesLedger::sum($items,'revenue')])->sortBy('date')->values()->all(),
            'rows'=>$rows->groupBy('product_id')->map(fn($items,$id)=>['id'=>$id,'name'=>$items->first()['product'],'unit'=>$items->first()['unit'],'quantity'=>round($items->sum('quantity'),3),'revenue'=>AnalyticsSalesLedger::sum($items,'revenue'),'url'=>'/products?product='.$id])->sortByDesc('revenue')->values()->all(),
            'groups'=>['category'=>$group('category'),'customer'=>$group('customer'),'channel'=>$group('channel'),
                'weekly'=>$rows->groupBy(fn($r)=>Date::parse($r['date'])->startOfWeek()->toDateString())->map(fn($g,$date)=>['date'=>$date,'revenue'=>AnalyticsSalesLedger::sum($g,'revenue')])->sortBy('date')->values()->all(),
                'monthly'=>$rows->groupBy(fn($r)=>substr($r['date'],0,7))->map(fn($g,$date)=>['date'=>$date,'revenue'=>AnalyticsSalesLedger::sum($g,'revenue')])->sortBy('date')->values()->all()], 'scope'=>'economic_sales'];
    }
    public function productRows(?string $asOf=null):array {
        $day=Date::parse($asOf??today()->toDateString());$ledger=app(AnalyticsSalesLedger::class)->rows($day->subDays(89)->toDateString(),$day->toDateString())->groupBy('product_id');
        $products=Product::with('category:id,name')->get();$stock=app(InventorySnapshotService::class)->forProducts($products);
        $history=AnalyticsSnapshot::where('entity_type','product')->where('warehouse_id',0)->whereDate('snapshot_date','>=',$day->subDays(89))->whereDate('snapshot_date','<=',$day)->orderBy('snapshot_date')->get()->groupBy('entity_id');
        $quality=QualityInspection::whereNotNull('finalized_at')->whereBetween('finalized_at',[$day->subDays(89)->startOfDay(),$day->endOfDay()])->whereNotIn('id',QualityInspection::whereNotNull('revision_of_id')->where('finalized_at','<=',$day->endOfDay())->pluck('revision_of_id'))->get()->groupBy('product_id');
        return $products->map(function($p)use($ledger,$stock,$day,$history,$quality){
            $sales=$ledger->get($p->id,collect());$s=$stock->get($p->id);$r=['id'=>$p->id,'name'=>$p->name,'unit'=>$p->unit,'category'=>$p->category?->name,'supplier_id'=>$p->supplier_id,'url'=>'/products?product='.$p->id];
            foreach([7,30,90] as $days)$r['sales_'.$days.'d']=round($sales->where('date','>=',$day->subDays($days-1)->toDateString())->sum('quantity'),3);
            $recent=$sales->where('date','>=',$day->subDays(29)->toDateString());$summary=app(AnalyticsSalesLedger::class)->summary($recent);
            $demand=max(0,$r['sales_30d']/30);$h=$history->get($p->id,collect());$avg=$h->avg(fn($h)=>(float)($h->facts['on_hand']??0));$inspections=$quality->get($p->id,collect());
            $stockouts=0;$previous=null;foreach($h as $observation){if(($observation->facts['available']??1)<=0&&($previous===null||($previous->facts['available']??1)>0||$previous->snapshot_date->diffInDays($observation->snapshot_date)>1))$stockouts++;$previous=$observation;}
            $ordinary=$sales->where('return',false);
            return $r+['revenue_30d'=>$summary['revenue'],'gross_profit'=>$summary['gross_profit'],'average_daily_demand'=>round($demand,6),'days_since_sale'=>$ordinary->isEmpty()?null:(int)Date::parse($ordinary->max('date'))->diffInDays($day),
                'available'=>$s['available'],'on_hand'=>$s['on_hand'],'reserved'=>$s['reserved'],'incoming'=>$s['incoming'],
                'stock_value'=>$p->inventory_value===null?null:Money::normalize($p->inventory_value),'days_of_supply'=>$demand>0?round($s['available']/$demand,2):null,
                'turnover'=>$h->count()===90 && $avg>0?round($r['sales_90d']/$avg,3):null,'snapshot_days'=>$h->count(),'min_quantity'=>(float)$p->min_quantity,'stockout_count_90d'=>$stockouts,
                'low_stock_days'=>$h->filter(fn($h)=>($h->facts['available']??0)<($h->facts['min_quantity']??0))->count(),
                'stockout_days'=>$h->filter(fn($h)=>($h->facts['available']??1)<=0)->count(),
                'return_rate'=>self::percent(abs($sales->where('return',true)->sum('quantity')),$sales->where('return',false)->sum('quantity')),
                'quality_failure_rate'=>self::percent($inspections->whereIn('status',['FAILED','failed','rejected'])->count(),$inspections->count()),
                'movement'=>$r['sales_90d']<=0?'non_moving':($r['sales_30d']<=0?'slow_moving':'moving')];
        })->all();
    }
    private function inventory(array $p):array {
        $rows=collect($this->productRows());$value=AnalyticsSalesLedger::sum($rows,'stock_value');
        if($this->can('suppliers.manage')){$suppliers=collect($this->supplierRows())->keyBy('id');$rows=$rows->map(fn($r)=>$r+['supplier_average_lead_time'=>$suppliers->get($r['supplier_id'])['average_lead_time']??null]);}
        $warehouses=WarehouseStock::with('warehouse:id,name','product:id,unit')->get()->groupBy(fn($s)=>$s->warehouse_id.':'.$s->product?->unit)->map(fn($s)=>['name'=>$s->first()->warehouse?->name,'unit'=>$s->first()->product?->unit,'available'=>round($s->sum('available_quantity'),3),'reserved'=>round($s->sum('reserved_quantity'),3),'url'=>'/warehouse-operations?tab=stock'])->values()->all();
        if(!$this->can('analytics.finance'))$rows=$rows->map(fn($r)=>array_diff_key($r,array_flip(['stock_value','revenue_30d','gross_profit'])));
        $history=AnalyticsSnapshot::where('entity_type','product')->where('warehouse_id',0)->whereDate('snapshot_date','>=',$p['from'])->whereDate('snapshot_date','<=',$p['to'])->orderBy('snapshot_date')->get()->groupBy(fn($s)=>$s->snapshot_date->toDateString());
        $metric=$this->can('analytics.finance')?'stock_value':'stockout_products';
        $trend=$history->map(fn($g,$date)=>['date'=>$date,$metric=>$metric==='stock_value'?AnalyticsSalesLedger::sum($g->map(fn($s)=>$s->facts),'stock_value'):$g->filter(fn($s)=>($s->facts['available']??1)<=0)->count()])->values()->all();
        return ['metrics'=>array_filter(['products'=>$rows->count(),'stock_value'=>$this->can('analytics.finance')?$value:null,'stockout_products'=>$rows->where('available','<=',0)->count(),'non_moving'=>$rows->where('movement','non_moving')->count()],fn($v)=>$v!==null),
            'rows'=>$rows->sortByDesc('sales_30d')->values()->all(),'warehouses'=>$warehouses,'scope'=>'current','trend'=>$trend,'trend_metric'=>$metric,
            'groups'=>['category'=>$rows->groupBy(fn($r)=>($r['category']??'—').':'.$r['unit'])->map(fn($g,$k)=>['name'=>$k,'available'=>round($g->sum('available'),3),'reserved'=>round($g->sum('reserved'),3),'incoming'=>round($g->sum('incoming'),3)])->values()->all()]];
    }
    public function supplierRows():array {
        return Supplier::get()->map(function($s){
            $c=app(SupplierPerformanceService::class)->scorecard($s);$d=$c['delivery'];$q=$c['quality'];
            $orders=PurchaseOrder::with('goodsReceipts')->where('supplier_id',$s->id)->whereNotIn('status',['draft','cancelled'])->get();
            $leads=[];$promised=[];$delays=[];
            foreach($orders as $o){$actual=$o->goodsReceipts->max('received_at');if($o->ordered_at&&$o->expected_at)$promised[]=self::days($o->ordered_at,$o->expected_at);if($o->ordered_at&&$actual)$leads[]=self::days($o->ordered_at,$actual);if($o->expected_at&&$actual)$delays[]=self::days($o->expected_at,$actual);}
            $claims=SupplierClaim::where('supplier_id',$s->id)->get();$resolution=$claims->whereNotNull('resolved_at')->map(fn($x)=>self::days($x->claim_date,$x->resolved_at))->filter(fn($v)=>$v!==null);
            return ['id'=>$s->id,'name'=>$s->name,'url'=>'/suppliers?supplier='.$s->id,'score'=>$c['overall_score'],'average_lead_time'=>$d['average_lead_time_days'],'promised_lead_time'=>self::average($promised),'lead_time_variance'=>self::variance($leads),'delay_variance'=>self::variance($delays),
                'average_delay'=>$d['average_days_late'],'on_time_rate'=>$d['on_time_delivery_percent'],'quality_failure_rate'=>$q['defect_rate'],'claim_rate'=>self::percent($claims->count(),$orders->count()),'claim_resolution_days'=>$resolution->isEmpty()?null:round($resolution->avg(),2),
                'purchase_value'=>$d['total_purchased_value'],'purchase_frequency'=>$orders->count(),'completion_rate'=>self::percent($d['completed_purchase_orders'],$orders->count()),'price_movement'=>$c['commercial']['historical_price_movement_percent']];
        })->all();
    }
    public function procurement(array $p):array {
        $orders=PurchaseOrder::with('supplier:id,name','items','goodsReceipts')->whereDate('ordered_at','>=',$p['from'])->whereDate('ordered_at','<=',$p['to'])->whereNotIn('status',['draft','cancelled'])->get();
        $rows=$orders->map(fn($o)=>['id'=>$o->id,'name'=>$o->po_number,'supplier'=>$o->supplier?->name,'status'=>$o->status,'purchase_value'=>$o->total_amount_eur,'currency'=>$o->currency,'expected_at'=>$o->expected_at?->toDateString(),'received_at'=>$o->received_at?->toDateString(),'url'=>'/purchase-orders?po='.$o->id]);
        $requests=PurchaseRequest::whereBetween('created_at',[$p['from'].' 00:00:00',$p['to'].' 23:59:59'])->get();$rfqs=Rfq::whereBetween('created_at',[$p['from'].' 00:00:00',$p['to'].' 23:59:59'])->get();
        return ['metrics'=>['purchase_spend'=>AnalyticsSalesLedger::sum($rows,'purchase_value'),'po_count'=>$orders->count(),'average_po_value'=>$orders->count()?Money::divide(AnalyticsSalesLedger::sum($rows,'purchase_value'),$orders->count()):null,
            'pr_conversion_rate'=>self::percent($requests->where('status','converted')->count(),$requests->count()),'rfq_count'=>$rfqs->count(),'awards'=>ProcurementAward::whereBetween('created_at',[$p['from'].' 00:00:00',$p['to'].' 23:59:59'])->count(),
            'partial_receipt_rate'=>self::percent($orders->where('status','partially_received')->count(),$orders->count()),'overdue_po_count'=>$orders->filter(fn($o)=>!in_array($o->status,['received','completed','cancelled'])&&$o->expected_at?->isPast())->count()],
            'rows'=>$rows->all(),'groups'=>['supplier'=>$rows->groupBy('supplier')->map(fn($g,$name)=>['name'=>$name,'purchase_value'=>AnalyticsSalesLedger::sum($g,'purchase_value'),'po_count'=>$g->count()])->values()->all(),
                'purchase_quantities'=>$orders->flatMap->items->groupBy('unit')->map(fn($g,$unit)=>['unit'=>$unit,'quantity'=>round($g->sum('quantity'),3)])->values()->all(),
                'procurement_outcomes'=>[['open_po_value'=>AnalyticsSalesLedger::sum($rows->whereNotIn('status',['received','completed']),'purchase_value'),'receipt_cycle_days'=>self::average($orders->map(fn($o)=>self::days($o->ordered_at,$o->goodsReceipts->max('received_at')??$o->received_at))->all())]]],
            'prices'=>ProductSupplierPriceHistory::with('productSupplier')->whereBetween('effective_at',[$p['from'].' 00:00:00',$p['to'].' 23:59:59'])->latest('effective_at')->limit(100)->get()->map(fn($x)=>['id'=>$x->id,'name'=>$x->productSupplier?->product_id,'price'=>$x->base_currency_price,'date'=>$x->effective_at->toDateString(),'url'=>'/products?product='.$x->productSupplier?->product_id])->all()];
    }
    public function customers(array $p):array {
        $sales=app(AnalyticsSalesLedger::class)->rows($p['from'],$p['to'])->groupBy('customer_id');
        $rows=Customer::get()->map(function($c)use($sales,$p){$credit=app(CustomerCreditService::class)->exposure($c);$r=$sales->get($c->id,collect());$orders=SalesOrder::where('customer_id',$c->id)->whereDate('order_date','>=',$p['from'])->whereDate('order_date','<=',$p['to'])->get();
            // Payment delay only uses a dated, fully settled document. Do not infer dates from today's FIFO allocation.
            $paid=Invoice::where('customer_id',$c->id)->whereNotNull('paid_at')->whereNotNull('due_at')->whereDate('paid_at','>=',$p['from'])->whereDate('paid_at','<=',$p['to'])->get();
            return ['id'=>$c->id,'name'=>$c->name,'revenue'=>AnalyticsSalesLedger::sum($r,'revenue'),'orders'=>$orders->count(),'average_order_value'=>$orders->count()?Money::divide(AnalyticsSalesLedger::sum($orders,'total_amount'),$orders->count()):null,
                'outstanding'=>$credit['current_debt'],'advance'=>$credit['advance'],'overdue'=>$credit['overdue'],'overdue_ratio'=>self::percent($credit['overdue'],$credit['current_debt']),'exposure'=>$credit['total_exposure'],'utilization'=>$credit['utilization_percent'],
                'orders_30d'=>$orders->filter(fn($o)=>$o->order_date->toDateString()>=Date::parse($p['to'])->subDays(29)->toDateString())->count(),
                'average_payment_delay'=>self::average($paid->map(fn($i)=>max(0,self::days($i->due_at,$i->paid_at)??0))->all()),'cancellation_rate'=>self::percent($orders->where('status','cancelled')->count(),$orders->count()),
                'return_rate'=>self::percent(abs(Money::minor(AnalyticsSalesLedger::sum($r->where('return',true),'revenue'))),Money::minor(AnalyticsSalesLedger::sum($r->where('return',false),'revenue'))),'last_purchase'=>$r->where('return',false)->max('date'),'url'=>'/customer-debts?customer='.$c->id];});
        return ['metrics'=>['outstanding'=>AnalyticsSalesLedger::sum($rows,'outstanding'),'overdue'=>AnalyticsSalesLedger::sum($rows,'overdue'),'advances'=>AnalyticsSalesLedger::sum($rows,'advance'),'top5_concentration'=>self::percent($rows->sortByDesc('revenue')->take(5)->sum('revenue'),$rows->sum('revenue'))],'rows'=>$rows->all(),'scope'=>'balances_current'];
    }
    public function orders(array $p):array {
        $orders=SalesOrder::with('intake.channel','items','dispatches','returns','tasks')->whereDate('order_date','>=',$p['from'])->whereDate('order_date','<=',$p['to'])->get();
        $rows=$orders->map(function($o){$state=app(OrderHubService::class)->state($o);$dispatch=$o->dispatches->min('dispatched_at');$started=$o->tasks->min('started_at');
            // No reliable ledger payment timestamp is stored on a credit order. Never infer it from a current paid flag.
            $paid=$o->payment_type==='cash'&&$state['payment']==='paid'?$o->dispatches->max('dispatched_at'):null;
            return ['id'=>$o->id,'name'=>$o->order_number,'status'=>$o->status,'channel'=>$o->intake?->channel?->name,'backordered'=>$state['backordered'],'delivery_status'=>$state['delivery'],
                'order_value'=>$this->can('analytics.finance')?$o->total_amount:null,'currency'=>$o->currency,
                'created_to_paid_days'=>self::days($o->created_at,$paid),'paid_to_fulfillment_days'=>$paid&&$started&&$paid<=$started?self::days($paid,$started):null,
                'fulfillment_to_dispatch_days'=>self::days($started,$dispatch),'fulfillment_days'=>self::days($o->created_at,$dispatch),'delivery_days'=>$o->status==='delivered'?self::days($dispatch,$o->completed_at):null,
                'url'=>'/fulfillment?order='.$o->id];});
        return ['metrics'=>['orders_created'=>$orders->count(),'orders_completed'=>$orders->where('status','delivered')->count(),'orders_cancelled'=>$orders->where('status','cancelled')->count(),
            'orders_returned'=>$orders->filter(fn($o)=>$o->returns->contains('status','resolved'))->count(),'backorder_rate'=>self::percent($rows->where('backordered',true)->count(),$orders->count()),'failed_delivery_rate'=>self::percent($rows->where('delivery_status','failed')->count(),$orders->filter(fn($o)=>$o->dispatches->isNotEmpty())->count()),
            'average_order_value'=>$this->can('analytics.finance')&&$orders->where('currency',CompanyCurrency::current())->isNotEmpty()?Money::divide(AnalyticsSalesLedger::sum($orders->where('currency',CompanyCurrency::current()),'total_amount'),$orders->where('currency',CompanyCurrency::current())->count()):null,
            'average_fulfillment_days'=>self::average($rows->pluck('fulfillment_days')->all()),'average_delivery_days'=>self::average($rows->pluck('delivery_days')->all()),
            'late_fulfillment_rate'=>self::percent($orders->where('status','!=','cancelled')->filter(fn($o)=>$o->requested_delivery_date&&(($o->completed_at??now())->toDateString()>$o->requested_delivery_date->toDateString()))->count(),$orders->where('status','!=','cancelled')->whereNotNull('requested_delivery_date')->count())],
            'rows'=>$rows->all(),'groups'=>['channel'=>$rows->groupBy('channel')->map(fn($g,$name)=>['name'=>$name,'orders_created'=>$g->count()])->values()->all()]];
    }
    public function logistics(array $p):array {
        $shipments=Shipment::whereBetween('created_at',[$p['from'].' 00:00:00',$p['to'].' 23:59:59'])->get();$costs=$this->can('analytics.finance')?LandedCost::where('status','posted')->where('base_currency',CompanyCurrency::current())->whereBetween('posted_at',[$p['from'].' 00:00:00',$p['to'].' 23:59:59'])->get():collect();
        $rows=$shipments->map(fn($s)=>['id'=>$s->id,'name'=>$s->vessel_name?:$s->tracking_number,'status'=>$s->status,'transit_days'=>self::days($s->departed_at,$s->arrival_date),
            'delay_days'=>$s->arrival_date&&$s->eta?max(0,self::days($s->eta,$s->arrival_date)??0):null,'eta_error_days'=>self::days($s->previous_eta??$s->eta,$s->arrival_date),'url'=>'/shipments/my-shipments?shipment='.$s->id]);
        return ['metrics'=>['shipments'=>$shipments->count(),'average_transit_days'=>self::average($rows->pluck('transit_days')->all()),'average_delay'=>self::average($rows->pluck('delay_days')->all())]+($this->can('analytics.finance')?['landed_cost'=>AnalyticsSalesLedger::sum($costs,'base_currency_amount')]:[]),
            'rows'=>$rows->all(),'shipment_intelligence'=>app(ShipmentIntelligenceService::class)->routes(), 'components'=>$costs->groupBy('cost_type')->map(fn($g,$k)=>['name'=>$k,'cost'=>AnalyticsSalesLedger::sum($g,'base_currency_amount')])->values()->all(),
            'groups'=>['shipment_costs'=>$costs->whereNotNull('shipment_id')->groupBy('shipment_id')->map(fn($g,$id)=>['name'=>(string)$id,'cost'=>AnalyticsSalesLedger::sum($g,'base_currency_amount'),'url'=>'/shipments/my-shipments?shipment='.$id])->values()->all()],
            'containers'=>ShipmentContainer::whereIn('shipment_id',$shipments->pluck('id'))->get()->map(fn($c)=>['id'=>$c->id,'name'=>$c->container_number,'volume_utilization'=>self::percent($c->volume_m3,$c->capacity_cbm),'weight_utilization'=>self::percent($c->gross_weight_kg,$c->capacity_weight_kg),'url'=>'/shipments/my-shipments?shipment='.$c->shipment_id])->all()];
    }
    public function quality(array $p):array {
        $q=QualityInspection::with('product:id,name,unit','supplier:id,name')->whereBetween('finalized_at',[$p['from'].' 00:00:00',$p['to'].' 23:59:59'])->get();
        // Corrections replace earlier outcomes for reporting, rather than counting the same receipt twice.
        $q=$q->whereNotIn('id',QualityInspection::whereNotNull('revision_of_id')->where('finalized_at','<=',$p['to'].' 23:59:59')->pluck('revision_of_id'));
        $claims=SupplierClaim::whereDate('claim_date','>=',$p['from'])->whereDate('claim_date','<=',$p['to'])->get();
        $defects=QualityDefect::with('category:id,name','product:id,name,unit')->whereIn('quality_inspection_id',$q->pluck('id'))->get();
        return ['metrics'=>['inspections'=>$q->count(),'pass_rate'=>self::percent($q->whereIn('status',['PASSED','passed','accepted'])->count(),$q->count()),'failure_rate'=>self::percent($q->whereIn('status',['FAILED','failed','rejected'])->count(),$q->count()),
            'defects'=>$defects->count(),'claims'=>$claims->count()],
            'rows'=>$q->map(fn($i)=>['id'=>$i->id,'name'=>$i->inspection_number,'product'=>$i->product?->name,'supplier'=>$i->supplier?->name,'unit'=>$i->product?->unit,'status'=>$i->status,'accepted'=>$i->accepted_quantity,'rejected'=>$i->rejected_quantity,'quarantined_quantity'=>$i->quarantine_quantity,'url'=>'/quality?inspection='.$i->id])->values()->all(),
            'groups'=>['defects'=>$defects->map(fn($d)=>['name'=>$d->category?->name,'supplier_id'=>$d->supplier_id,'product'=>$d->product?->name,'unit'=>$d->product?->unit,'quantity'=>$d->affected_quantity,'severity'=>$d->severity,'url'=>'/quality?inspection='.$d->quality_inspection_id])->all()],
            'claims'=>$claims->map(fn($c)=>['id'=>$c->id,'name'=>$c->claim_number,'status'=>$c->status,'resolution_days'=>self::days($c->claim_date,$c->resolved_at),'url'=>'/quality?claim='.$c->id])->all()];
    }
    public static function percent($n,$d):?float{return (float)$d>0?round(100*(float)$n/(float)$d,2):null;}
    public static function average(array $values):?float{$values=array_values(array_filter($values,fn($x)=>$x!==null));return $values?round(array_sum($values)/count($values),3):null;}
    public static function variance(array $values):?float{$values=array_values(array_filter($values,fn($x)=>$x!==null));$avg=self::average($values);return count($values)>1?round(array_sum(array_map(fn($x)=>($x-$avg)**2,$values))/count($values),3):null;}
    public static function days($start,$end):?float {return $start&&$end?round(Date::parse($start)->diffInSeconds(Date::parse($end),false)/86400,3):null;}
}
