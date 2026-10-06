<?php
namespace App\Services;
use App\Models\{Shipment,ShipmentIntelligence,ShipmentHistory,Company,User,ActivityLog};
use Illuminate\Support\Facades\{Auth,Cache,DB,Log};
use Illuminate\Support\Str;
use Carbon\CarbonImmutable as Date;

final class ShipmentIntelligenceService {
    public function can(string $permission): bool { return Auth::user()?->company_id&&app(PermissionService::class)->roleHasPermission(Auth::user()->role,$permission); }
    public function authorize(bool $write=false): void {
        abort_unless($this->can('shipments.view'),403);
        if($write)foreach(['shipments.manage','analytics.view','inventory.view'] as $p)abort_unless($this->can($p),403);
    }
    public static function invalidate(int $company,int $shipment): void {
        $key='logistics-dirty:'.$company;$ids=Cache::get($key,[]);$ids[]=$shipment;
        Cache::put($key,array_values(array_unique($ids)),now()->addDay());
    }
    public static function invalidateProducts(int $company,array $ids): void {
        if(!$ids)return;$key='logistics-dirty-products:'.$company;
        Cache::put($key,array_values(array_unique(array_merge(Cache::get($key,[]),$ids))),now()->addDay());
    }

    public function refresh(int $id): array {
        $this->authorize(true);
        $result=DB::transaction(function()use($id){
            $s=Shipment::lockForUpdate()->findOrFail($id);$reader=app(ShipmentIntelligenceEvidence::class);$math=app(ShipmentEtaCalculator::class);
            $facts=$reader->facts($s);$history=$reader->routeHistory($s);
            $observer=app(\App\Observers\ShipmentIntelligenceObserver::class);$observer->capture($s,true);
            foreach($s->milestones as $m)$observer->capture($m,true);
            $route=['origin'=>$facts['origin'],'destination'=>$facts['destination'],'mode'=>$facts['mode'],
                'transit'=>$math->distribution($history,'transit_days'),'warehouse'=>$math->distribution($history,'warehouse_days'),
                'port_to_warehouse'=>$math->distribution($history,'tail_days')];
            $eta=$math->predict($facts,$route);$impact=$reader->impact($s,$eta);$delay=$math->attribution($facts['milestones']);
            $scheduleActual=$facts['schedule_target']==='warehouse'?($facts['warehouse_actual']??$facts['receipt_actual']):$facts['port_actual'];
            $late=(!$facts['completed']&&!$scheduleActual&&$facts['operational_eta']&&$facts['operational_eta']<today()->toDateString())
                ||collect($delay)->contains(fn($d)=>($d['late_days']??0)>=1&&!($d['actual']??null));
            $stale=!$facts['port_actual']&&!$facts['warehouse_actual']&&!$facts['completed']&&in_array($facts['freshness']['state'],['stale','missing','invalid_mmsi']);
            $risk=$facts['completed']?'ON_TRACK':($impact['critical_products']>0?'CRITICAL':($late?'DELAYED':($impact['exposed_products']>0?'AT_RISK':($stale?'WATCH':(!$eta['predicted']?'INSUFFICIENT_DATA':'ON_TRACK')))));
            if(!$facts['completed']&&!$scheduleActual&&$risk==='ON_TRACK'&&$eta['predicted']&&$facts['operational_eta']&&$eta['predicted']>$facts['operational_eta'])$risk='AT_RISK';
            $confidence=$stale?'limited':$eta['confidence'];
            $reason=$facts['completed']?'recorded_completion':($impact['critical_products']?'critical_inventory_exposure':($impact['exposed_products']?'arrival_after_inventory_requirement':($late?'milestone_or_schedule_overdue':($stale?'tracking_not_fresh':($eta['predicted']?'recorded_schedule_or_route_evidence':'insufficient_milestones')))));
            $alternatives=[];
            foreach($impact['products'] as $p)if($p['exposed']){
                foreach($p['transfer_options'] as $a)$alternatives[]=$a+['product_id'=>$p['product']['id']];
                foreach($p['alternative_incoming'] as $a)$alternatives[]=['type'=>'existing_incoming','product_id'=>$p['product']['id'],'purchase_order_id'=>$a['purchase_order_id'],'quantity'=>$a['quantity'],'arrival'=>$a['expected_at'],'url'=>'/purchase-orders?po='.$a['purchase_order_id']];
                $alternatives[]=['type'=>'procurement_review','product_id'=>$p['product']['id'],'url'=>'/inventory-intelligence?view=decisions&product='.$p['product']['id'].($p['warehouse']?'&warehouse_id='.$p['warehouse']['id']:''),'qualification'=>'review_existing_supplier_and_purchase_request_alternatives_no_automatic_purchase'];
                if($p['customer_orders'])$alternatives[]=['type'=>'customer_order_review','product_id'=>$p['product']['id'],'url'=>'/fulfillment','qualification'=>'recorded_commitments_not_predicted_cancellations'];
            }
            if(in_array($risk,['CRITICAL','AT_RISK','DELAYED','WATCH']))$alternatives[]=['type'=>'operational_follow_up','url'=>'/shipments/my-shipments?shipment='.$id,'qualification'=>'manager_follow_up_not_an_automated_expedite'];
            $evidence=$facts+['route'=>$route,'delay_evidence'=>$delay,'reason'=>$reason,'logic_version'=>config('shipment_intelligence.version'),
                'supplier_feedback'=>$facts['supplier_attribution_supported']?array_values(array_filter($delay,fn($d)=>$d['category']==='SUPPLIER_PREPARATION'&&($d['actual']??null))):[],
                'unknowns'=>array_values(array_filter([!$eta['predicted']?'eta_unavailable':null,$eta['target']!=='warehouse'?'warehouse_arrival_unknown':null,$eta['limited_historical_evidence']?'limited_historical_evidence':null,$stale?'ais_tracking_not_fresh':null]))];
            $old=ShipmentIntelligence::where('shipment_id',$id)->where('is_current',true)->latest('id')->first();
            $stable=$evidence;unset($stable['freshness']['age_minutes'],$stable['freshness']['coordinates'],$stable['freshness']['updated_at']);
            foreach($stable['milestones'] as &$m)unset($m['known_at']);unset($m);
            // Vessel positions and incidental timestamps do not create prediction
            // versions/events. Freeze a new evidence version on material facts or day.
            $fingerprint=hash('sha256',json_encode([$eta,$impact,$stable,$risk,$confidence,today()->toDateString()],JSON_PRESERVE_ZERO_FRACTION));
            if($old&&hash_equals($old->fingerprint,$fingerprint)){$old->update(['checked_at'=>now()]);$this->evaluateOutcomes($s,$facts);return $this->present($old);}
            ShipmentIntelligence::where('shipment_id',$id)->where('is_current',true)->update(['is_current'=>false]);
            $row=ShipmentIntelligence::create(['company_id'=>$s->company_id,'shipment_id'=>$id,'version'=>(string)Str::uuid(),
                'fingerprint'=>$fingerprint,'risk'=>$risk,'confidence'=>$confidence,'eta'=>$eta,'evidence'=>$evidence,'impact'=>$impact,
                'alternatives'=>$alternatives,'generated_at'=>now(),'evidence_cutoff'=>now(),'checked_at'=>now()]);
            $this->events($row,$old);$this->evaluateOutcomes($s,$facts);
            $products=array_column(array_column($impact['products'],'product'),'id');EnterpriseDecisionService::invalidate($s->company_id,$products);
            return $this->present($row);
        });
        $key='logistics-dirty:'.Auth::user()->company_id;Cache::put($key,array_values(array_diff(Cache::get($key,[]),[$id])),now()->addDay());
        return $result;
    }

    private function events(ShipmentIntelligence $r,?ShipmentIntelligence $old): void {
        $s=$r->shipment; $events=[];
        if(!$old||$old->risk!==$r->risk)$events[]='risk_changed';
        if($old&&$r->eta['predicted']&&($old->eta['predicted']??null)&&abs(Date::parse($old->eta['predicted'])->diffInDays(Date::parse($r->eta['predicted'])))>=config('shipment_intelligence.material_eta_days'))$events[]='eta_materially_changed';
        if($r->impact['exposed_products']>0&&(!$old||$old->impact['exposed_products']!==$r->impact['exposed_products']))$events[]='inventory_exposure';
        if(!$r->evidence['completed']&&!$r->evidence['port_actual']&&!$r->evidence['warehouse_actual']&&in_array($r->evidence['freshness']['state'],['stale','missing','invalid_mmsi'])&&(!$old||$old->evidence['freshness']['state']!==$r->evidence['freshness']['state']))$events[]='tracking_stale';
        $soon=fn($row)=>!$row->evidence['completed']&&!$row->evidence['warehouse_actual']&&$row->eta['predicted']&&$row->eta['predicted']>=today()->toDateString()&&$row->eta['predicted']<=today()->addDays(config('shipment_intelligence.arriving_soon_days'))->toDateString();
        if($soon($r)&&(!$old||!$soon($old)))$events[]='arriving_soon';
        foreach($events as $event)app(BusinessEventService::class)->record('shipment.intelligence.'.$event,$s,$s->tracking_number,
            ['risk'=>$r->risk,'confidence'=>$r->confidence,'predicted_eta'=>$r->eta['predicted'],'exposed_products'=>$r->impact['exposed_products'],
                'critical_products'=>$r->impact['critical_products'],'no_alternative_incoming'=>!collect($r->impact['products'])->contains(fn($p)=>$p['exposed']&&$p['alternative_incoming']),
                'intelligence_id'=>$r->id,'primary_reason'=>$r->evidence['reason']], 'logistics:'.$r->version.':'.$event);
        $this->notify($r,$events);
    }

    private function notify(ShipmentIntelligence $r,array $events): void {
        if(!(($r->risk==='CRITICAL'&&in_array('risk_changed',$events,true))||in_array('eta_materially_changed',$events,true)||in_array('tracking_stale',$events,true)))return;
        foreach(User::where('company_id',$r->company_id)->where('is_active',true)->get() as $user){
            if(!app(PermissionService::class)->roleHasPermission($user->role,'shipments.view'))continue;
            \App\Models\Notification::firstOrCreate(['company_id'=>$r->company_id,'user_id'=>$user->id,'type'=>'shipment_intelligence','data->intelligence_version'=>$r->version],
                ['title'=>'Shipment needs review','message'=>$r->evidence['reference'].' · '.$r->risk,'data'=>['intelligence_version'=>$r->version,'shipment_id'=>$r->shipment_id,
                    'title_sq'=>'Dërgesa kërkon rishikim','url'=>'/shipments/my-shipments?view=intelligence&shipment='.$r->shipment_id]]);
        }
    }
    public function present(ShipmentIntelligence $r): array {
        $this->authorize();$s=Shipment::findOrFail($r->shipment_id);$data=$r->toArray();
        $data['stale']=$r->checked_at->lt(now()->subHours(config('shipment_intelligence.prediction_max_age_hours')));
        $latestObservation=ShipmentHistory::where('shipment_id',$s->id)->where('event_type','intelligence.observation')->max('created_at');
        if($latestObservation&&Date::parse($latestObservation)->gt($r->evidence_cutoff))$data['stale']=true;
        $data['url']='/shipments/my-shipments?view=intelligence&shipment='.$s->id;
        // The frozen freshness explains the prediction; the latest sample is
        // read directly without route/inventory recomputation.
        $position=$s->position_updated_at; $fresh=$data['evidence']['freshness'];
        if($fresh['applicable']){
            $fresh['updated_at']=$position?->toIso8601String();$fresh['age_minutes']=$position&&$position->lte(now())?round($position->diffInMinutes(now()),1):null;
            $fresh['state']=!preg_match('/^[1-9][0-9]{8}$/',(string)$s->mmsi)?'invalid_mmsi':($fresh['age_minutes']===null?'missing':($fresh['age_minutes']>config('shipment_intelligence.position_stale_hours')*60?'stale':($fresh['age_minutes']>config('tracking.live_position_minutes',10)?'last_known':'fresh')));
            $fresh['coordinates']=$position&&$s->current_lat!==null&&$s->current_lng!==null?['latitude'=>$s->current_lat,'longitude'=>$s->current_lng]:null;
            $fresh['mmsi']=$s->mmsi;$fresh['vessel_name']=$s->vessel_name;
        }
        $data['tracking_freshness']=$fresh;
        if($data['stale'])$data['confidence']='limited';
        if(!$this->can('inventory.view')){$data['impact']=['restricted'=>true];$data['alternatives']=array_values(array_filter($data['alternatives'],fn($a)=>$a['type']==='operational_follow_up'));}
        elseif(!$this->can('fulfillment.view'))foreach($data['impact']['products'] as &$p)unset($p['customer_orders']);
        unset($p);
        if(!$this->can('purchase_orders.view'))foreach($data['impact']['products']??[] as &$p){unset($p['po_ids'],$p['po_allocations'],$p['alternative_incoming']);}unset($p);
        $data['alternatives']=array_values(array_filter($data['alternatives'],fn($a)=>match($a['type']){
            'procurement_review'=>$this->can('analytics.view')&&$this->can('inventory.view'),
            'existing_incoming'=>$this->can('purchase_orders.view'),'customer_order_review'=>$this->can('fulfillment.view'),
            'warehouse_transfer'=>$this->can('transfers.view'),default=>true}));
        return $data;
    }
    public function detail(int $shipment): array {
        $this->authorize();Shipment::findOrFail($shipment);
        $row=ShipmentIntelligence::where('shipment_id',$shipment)->where('is_current',true)->latest('id')->first();
        if(!$row)return ['pending'=>true,'shipment_id'=>$shipment];
        return $this->present($row)+['history'=>ShipmentIntelligence::where('shipment_id',$shipment)->latest('id')->limit(25)->get()->map(fn($r)=>$r->only('id','version','risk','eta','generated_at','outcome'))->all(),
            'observations'=>ShipmentHistory::where('shipment_id',$shipment)->where('event_type','intelligence.observation')->latest('id')->limit(40)->get(['id','metadata','created_at'])->toArray()];
    }
    public function listing(array $f=[]): array {
        $this->authorize();$q=ShipmentIntelligence::where('is_current',true)->whereHas('shipment',fn($q)=>$q->whereNull('archived_at'));
        $view=$f['view']??'attention';
        if($view==='attention')$q->whereIn('risk',['WATCH','AT_RISK','DELAYED','CRITICAL']);
        if($view==='delayed')$q->whereIn('risk',['DELAYED','CRITICAL']);
        if($view==='transit')$q->where('evidence->completed',false)->whereNull('evidence->warehouse_actual')->whereNotNull('evidence->departure');
        if($view==='completed')$q->where('evidence->completed',true);
        if($view==='soon')$q->where('evidence->completed',false)->whereNull('evidence->warehouse_actual')->where('eta->predicted','>=',today()->toDateString())->where('eta->predicted','<=',today()->addDays(config('shipment_intelligence.arriving_soon_days'))->toDateString());
        if(!empty($f['q']))$q->whereHas('shipment',fn($s)=>$s->where('tracking_number','like','%'.mb_substr($f['q'],0,100).'%')->orWhere('vessel_name','like','%'.mb_substr($f['q'],0,100).'%'));
        if(!empty($f['risk']))$q->where('risk',$f['risk']);
        return $q->orderByRaw("CASE risk WHEN 'CRITICAL' THEN 0 WHEN 'DELAYED' THEN 1 WHEN 'AT_RISK' THEN 2 WHEN 'WATCH' THEN 3 ELSE 4 END")->latest('id')->paginate(30)->through(fn($r)=>$this->present($r))->toArray();
    }

    public function routes(array $f=[]): array {
        $this->authorize();
        $rows=ShipmentIntelligence::where('is_current',true)->when(!empty($f['origin']),fn($q)=>$q->where('evidence->origin',$f['origin']))
            ->when(!empty($f['destination']),fn($q)=>$q->where('evidence->destination',$f['destination']))->latest('id')->limit(200)->get();
        $routes=$rows->groupBy(fn($r)=>json_encode([$r->evidence['origin'],$r->evidence['destination'],$r->evidence['mode']]))
            ->map(fn($g)=>$g->sortByDesc('checked_at')->first()->evidence['route'])->values()->all();
        $evaluated=ShipmentIntelligence::whereNotNull('outcome')->when(!empty($f['origin']),fn($q)=>$q->where('evidence->origin',$f['origin']))
            ->when(!empty($f['destination']),fn($q)=>$q->where('evidence->destination',$f['destination']))
            ->orderBy('generated_at')->get()->filter(fn($r)=>$r->outcome['eligible']??false)->unique(fn($r)=>$r->shipment_id.':'.$r->eta['target']);
        $n=$evaluated->count();
        return ['routes'=>$routes,'performance'=>['completed_genuine_predictions'=>$n,
            'mae_days'=>$n?round($evaluated->avg(fn($r)=>abs($r->outcome['error_days'])),2):null,
            'baseline_mae_days'=>$n?round($evaluated->avg(fn($r)=>abs($r->outcome['baseline_error_days'])),2):null,
            'bias_days'=>$n?round($evaluated->avg(fn($r)=>$r->outcome['error_days']),2):null,
            'interval_coverage'=>$n?round($evaluated->filter(fn($r)=>$r->outcome['in_range'])->count()/$n,3):null,
            'qualification'=>'first_frozen_prearrival_prediction_per_shipment_and_target; tests_not_company_performance',
            'model_status'=>'interpretable_historical_baseline_no_ML_champion_claim']];
    }
    public function productRisk(int $id): array {
        $this->authorize();abort_unless($this->can('inventory.view'),403);\App\Models\Product::findOrFail($id);
        return ['shipments'=>ShipmentIntelligence::where('is_current',true)->latest('id')->limit(200)->get()
            ->filter(fn($r)=>collect($r->impact['products'])->contains(fn($p)=>$p['product']['id']===$id))->map(fn($r)=>$this->present($r))->values()->all()];
    }
    private function evaluateOutcomes(Shipment $s,array $facts): void {
        foreach(ShipmentIntelligence::where('shipment_id',$s->id)->whereNull('outcome')->get() as $r){
            $actual=$r->eta['target']==='warehouse'?$facts['warehouse_actual']:$facts['port_actual'];
            if(!$actual||$actual>today()->toDateString())continue;
            $pred=$r->eta['predicted'];$base=$r->eta['baseline']??null;
            $eligible=$pred&&$base&&$r->generated_at->toDateString()<$actual;
            $r->update(['outcome'=>['actual_arrival'=>$actual,'recorded_at'=>now()->toIso8601String(),'eligible'=>(bool)$eligible,
                'error_days'=>$pred?Date::parse($actual)->diffInDays(Date::parse($pred),false):null,
                'baseline_error_days'=>$base?Date::parse($actual)->diffInDays(Date::parse($base),false):null,
                'in_range'=>$pred&&$actual>=$r->eta['range_start']&&$actual<=$r->eta['range_end'],
                'qualification'=>'frozen_prediction_no_post_outcome_retraining_or_causal_claim']]);
        }
    }
    public function scheduled(): int {
        $before=Auth::user();$done=0;$started=microtime(true);
        try{foreach(Company::orderBy('id')->cursor() as $company){
            if(microtime(true)-$started>=config('shipment_intelligence.worker_seconds'))break;
            $actor=User::withoutGlobalScopes()->where('company_id',$company->id)->whereIn('role',['admin','manager'])->where('is_active',true)->first();if(!$actor)continue;
            Auth::setUser($actor);if(!$this->can('shipments.manage')||!$this->can('analytics.view')||!$this->can('inventory.view'))continue;
            $lock=Cache::lock('logistics-worker:'.$company->id,60);if(!$lock->get())continue;
            try{
                $dirty=Cache::get('logistics-dirty:'.$company->id,[]);
                $products=Cache::get('logistics-dirty-products:'.$company->id,[]);$consume=array_slice($products,0,10);
                if($consume){$affected=Shipment::active()->where(fn($q)=>$q->whereHas('items',fn($i)=>$i->whereIn('product_id',$consume))->orWhereHas('purchaseOrder.items',fn($i)=>$i->whereIn('product_id',$consume))->orWhereHas('purchaseOrders.items',fn($i)=>$i->whereIn('product_id',$consume)))->orderBy('id')->limit(500)->pluck('id')->all();$dirty=array_values(array_unique(array_merge($dirty,$affected)));Cache::put('logistics-dirty-products:'.$company->id,array_values(array_diff($products,$consume)),now()->addDay());}
                $candidates=Shipment::active()->where(fn($q)=>$q->whereIn('id',$dirty)->orWhereDoesntHave('intelligence',fn($i)=>$i->where('is_current',true)->where('checked_at','>',now()->subMinutes(config('shipment_intelligence.refresh_minutes')))))
                    ->orderBy('id')->limit(config('shipment_intelligence.batch_size'))->pluck('id');
                foreach($candidates as $id){if(microtime(true)-$started>=config('shipment_intelligence.worker_seconds'))break;
                    try{$this->refresh($id);$done++;$dirty=array_values(array_diff($dirty,[$id]));}catch(\Throwable $e){Log::warning('Shipment intelligence refresh deferred.',['shipment_id'=>$id,'error'=>$e->getMessage()]);}
                }
                Cache::put('logistics-dirty:'.$company->id,$dirty,now()->addDay());
            }finally{$lock->release();}
        }}finally{$before?Auth::setUser($before):Auth::forgetUser();}return $done;
    }
}
