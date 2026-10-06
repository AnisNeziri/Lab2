<?php
namespace App\Services;
use App\Models\{Shipment,ShipmentHistory,ShipmentItem,Product,PurchaseOrder,SalesOrderItem,Warehouse,GoodsReceipt};
use Carbon\CarbonImmutable as Date;

/** Company-scoped read adapter. PO quantity remains the single incoming authority. */
final class ShipmentIntelligenceEvidence {
    public function facts(Shipment $s): array {
        $s->loadMissing(['milestones','containers','items.purchaseOrderItem','items.product','purchaseOrders.items','purchaseOrder.items','warehouse']);
        $milestones=$s->milestones->filter(fn($m)=>$m->created_at->lte(now()))->map(fn($m)=>['id'=>$m->id,'scope'=>$m->scope_key,'container_id'=>$m->shipment_container_id,
            'type'=>$m->milestone_type,'status'=>$m->status,'planned'=>$m->planned_at?->toIso8601String(),
            'estimated'=>$m->estimated_at?->toIso8601String(),'actual'=>$m->actual_at&&$m->actual_at->lte(now())?$m->actual_at->toIso8601String():null,
            'source'=>$m->source,'known_at'=>$m->updated_at->toIso8601String()])->all();
        $date=function($type,$key)use($milestones){$rows=collect($milestones)->where('type',$type)->whereNotNull($key);return $rows->isNotEmpty()?substr($rows->max($key),0,10):null;};
        $departure=$date('vessel_departure','actual')??($s->departed_at&&$s->departed_at->lte(now())?$s->departed_at->toDateString():null);
        if (!$departure&&$s->containers->isNotEmpty()&&$s->containers->every(fn($c)=>$c->actual_departure&&$c->actual_departure->lte(now()))) $departure=$s->containers->max('actual_departure')->toDateString();
        $portActual=$date('destination_port','actual');
        $portActual??=$s->arrival_date&&$s->arrival_date->lte(now())?$s->arrival_date->toDateString():null;
        if (!$portActual&&$s->containers->isNotEmpty()&&$s->containers->every(fn($c)=>$c->actual_arrival&&$c->actual_arrival->lte(now()))) $portActual=$s->containers->max('actual_arrival')->toDateString();
        $warehouseActual=$date('warehouse_arrival','actual');
        $receiptActual=$date('goods_receipt','actual');
        $warehouseSchedule=$date('warehouse_arrival','estimated')??$date('warehouse_arrival','planned');
        $ais=$s->vessel_details['aisstream']??[]; $voyage=$ais['voyage']??[];
        $carrier=isset($voyage['eta'])?Date::parse($voyage['eta'])->toDateString():null;
        $identityChange=ShipmentHistory::where('shipment_id',$s->id)->where('event_type','intelligence.observation')->where('metadata->entity_type','Shipment')->latest('id')->get(['id','metadata','created_at'])->first(function($row){
            if(empty($row->metadata['revision_of']))return false;$previous=ShipmentHistory::find($row->metadata['revision_of']);
            return $previous&&($previous->metadata['values']['mmsi']??null)!==($row->metadata['values']['mmsi']??null);
        });
        $vesselChanged=$identityChange&&(!isset($voyage['received_at'])||Date::parse($voyage['received_at'])->lt($identityChange->created_at));
        if($vesselChanged)$carrier=null;
        $mmsiValid=(bool)preg_match('/^[1-9][0-9]{8}$/',(string)$s->mmsi);
        $position=$s->position_updated_at; $age=$position&&$position->lte(now())?round($position->diffInMinutes(now()),1):null;
        $freshness=['applicable'=>in_array($s->transport_mode,['sea','ocean'])&&$s->tracking_provider==='aisstream',
            'valid_mmsi'=>$mmsiValid,'mmsi'=>$s->mmsi,'updated_at'=>$position?->toIso8601String(),'age_minutes'=>$age,
            'state'=>'not_applicable','coordinates'=>null,'vessel_name'=>$s->vessel_name,'carrier_eta_observed_at'=>$voyage['received_at']??null,'vessel_changed_since_carrier_observation'=>(bool)$vesselChanged];
        if ($freshness['applicable']) {
            $freshness['state']=!$mmsiValid?'invalid_mmsi':($age===null?'missing':($age>config('shipment_intelligence.position_stale_hours')*60?'stale':($age>config('tracking.live_position_minutes',10)?'last_known':'fresh')));
            if ($age!==null&&is_numeric($s->current_lat)&&is_numeric($s->current_lng)&&abs($s->current_lat)<=90&&abs($s->current_lng)<=180) $freshness['coordinates']=['latitude'=>$s->current_lat,'longitude'=>$s->current_lng];
        }
        $mode=match($s->transport_mode){'sea'=>'ocean',default=>$s->transport_mode?:'unknown'};
        $last=collect($milestones)->whereNotNull('actual')->sortByDesc('actual')->first();
        $current=$last['type']??'purchase_order';
        $lastDate=isset($last['actual'])?substr($last['actual'],0,10):null;
        if($departure&&(!$lastDate||$departure>$lastDate)){$current='sea_transit';$lastDate=$departure;}
        if($portActual&&(!$lastDate||$portActual>$lastDate))$current='destination_port';
        if($warehouseActual)$current='warehouse_arrival';
        if($receiptActual)$current='goods_receipt';
        $suppliers=$s->purchaseOrders->concat($s->purchaseOrder?collect([$s->purchaseOrder]):collect())->pluck('supplier_id')->filter()->unique();
        $supplierAttribution=$suppliers->count()<=1&&($s->supplier_id||$suppliers->count()===1)&&(!$s->supplier_id||$suppliers->isEmpty()||$suppliers->first()===$s->supplier_id);
        $orders=$s->purchaseOrders->concat($s->purchaseOrder?collect([$s->purchaseOrder]):collect())->unique('id');
        $receivingComplete=$orders->isNotEmpty()&&$orders->every(fn($po)=>in_array($po->status,['received','completed'],true)&&$po->items->isNotEmpty()&&$po->items->every(fn($i)=>(float)($i->received_base_quantity??$i->received_quantity)+.0005>=(float)($i->base_quantity??$i->quantity)));
        if($receivingComplete)$current='goods_receipt';
        return ['shipment_id'=>$s->id,'reference'=>$s->tracking_number,'status'=>$s->status,'mode'=>$mode,
            'origin'=>$s->origin_port,'destination'=>$s->destination_port,'warehouse'=>$s->warehouse?->only('id','name'),
            'supplier_id'=>$s->supplier_id??$s->purchaseOrder?->supplier_id,'supplier_attribution_supported'=>$supplierAttribution,'milestones'=>$milestones,
            'departure'=>$departure,'port_actual'=>$portActual,'warehouse_actual'=>$warehouseActual,
            'receipt_actual'=>$receiptActual,'warehouse_schedule'=>$warehouseSchedule,
            'carrier_eta'=>$carrier,'operational_eta'=>$s->eta?->toDateString(),
            'schedule_target'=>in_array($mode,['ocean','multimodal'])?'port':'warehouse',
            'previous_eta'=>$s->previous_eta?->toDateString(),'freshness'=>$freshness,
            'current_milestone'=>$current,
            'containers'=>$s->containers->map(fn($c)=>['id'=>$c->id,'reference'=>$c->container_number,'status'=>$c->status,'eta'=>$c->eta?->toDateString()])->all(),
            'receiving_complete'=>$receivingComplete,
            'completed'=>(bool)($receiptActual||$receivingComplete||($s->status==='delivered'&&$orders->isEmpty())),'archived'=>(bool)$s->archived_at];
    }

    public function routeHistory(Shipment $s): array {
        if (!$s->origin_port||!$s->destination_port) return [];
        $modes=in_array($s->transport_mode,['sea','ocean'])?['sea','ocean']:[$s->transport_mode];
        $rows=Shipment::with(['milestones','containers'])->whereKeyNot($s->id)->where('origin_port',$s->origin_port)
            ->where('destination_port',$s->destination_port)->whereIn('transport_mode',$modes)
            ->where('created_at','<=',now())->latest('id')->limit(config('shipment_intelligence.history_limit'))->get();
        $history=[];
        foreach ($rows as $row) {
            $actual=fn($type)=>$row->milestones->filter(fn($m)=>$m->milestone_type===$type&&$m->actual_at&&$m->actual_at->lte(now())&&$m->updated_at->lte(now()))->max('actual_at')?->toDateString();
            $departure=$actual('vessel_departure')??($row->departed_at&&$row->departed_at->lte(now())?$row->departed_at->toDateString():null);
            $port=$actual('destination_port')??($row->arrival_date&&$row->arrival_date->lte(now())?$row->arrival_date->toDateString():null);$warehouse=$actual('warehouse_arrival');
            if(!$departure&&$row->containers->isNotEmpty()&&$row->containers->every(fn($c)=>$c->actual_departure&&$c->actual_departure->lte(now())))$departure=$row->containers->max('actual_departure')->toDateString();
            if(!$port&&$row->containers->isNotEmpty()&&$row->containers->every(fn($c)=>$c->actual_arrival&&$c->actual_arrival->lte(now())))$port=$row->containers->max('actual_arrival')->toDateString();
            $arrival=$warehouse??$port;
            if(!$departure||!$arrival||$arrival<$departure||$arrival>today()->toDateString())continue;
            $duration=fn($a,$b)=>$a&&$b&&$b>=$a?round(Date::parse($a)->diffInDays(Date::parse($b)),1):null;
            $history[]=['shipment_id'=>$row->id,'actual_arrival'=>$arrival,'known_at'=>$row->updated_at->toIso8601String(),
                'transit_days'=>$duration($departure,$port),
                'warehouse_days'=>$duration($departure,$warehouse),
                'tail_days'=>$duration($port,$warehouse),
                'supplier_id'=>$row->supplier_id,'qualification'=>'recorded_operational_not_synthetic'];
        }
        return $history;
    }

    public function impact(Shipment $s, array $eta): array {
        $s->loadMissing(['items.purchaseOrderItem','purchaseOrders.items.product','purchaseOrder.items.product']);
        $orders=$s->purchaseOrders->concat($s->purchaseOrder?collect([$s->purchaseOrder]):collect())->unique('id');
        $lines=[]; $unknown=[];
        if ($s->items->isNotEmpty()) {
            // Each PO-item allocation is capped once at remaining authoritative
            // quantity. A container and its parent shipment are never counted twice.
            foreach ($s->items->groupBy(fn($i)=>$i->purchase_order_item_id?'po:'.$i->purchase_order_item_id:'item:'.$i->id) as $group) {
                $first=$group->first(); $poItem=$first->purchaseOrderItem; $product=$first->product;
                if (!$product) continue;
                if($poItem&&($poItem->inventory_unit?:$poItem->unit)!==$product->unit){$unknown[]='unqualified_inventory_unit:'.$first->id;continue;}
                $base=$group->every(fn($i)=>$i->base_quantity!==null)?$group->sum(fn($i)=>(float)$i->base_quantity):null;
                if ($base===null) {$unknown[]='unqualified_item_unit:'.$first->id;continue;}
                if ($poItem) $base=min($base,max(0,(float)($poItem->base_quantity??$poItem->quantity)-(float)($poItem->received_base_quantity??$poItem->received_quantity)));
                $w=$s->warehouse_id??$orders->firstWhere('id',$poItem?->purchase_order_id)?->warehouse_id;
                $lines[]=['product'=>$product,'quantity'=>round($base,3),'warehouse_id'=>$w,'po_id'=>$poItem?->purchase_order_id,'allocation'=>'explicit_shipment_item'];
            }
        } else foreach ($orders as $po) {
            $peers=Shipment::active()->whereKeyNot($s->id)->whereNotIn('status',['delivered','cancelled'])->where(fn($q)=>$q->where('purchase_order_id',$po->id)->orWhereHas('purchaseOrders',fn($r)=>$r->where('purchase_orders.id',$po->id)))->exists();
            if ($peers) {$unknown[]='multiple_shipments_without_item_allocations:'.$po->id;continue;}
            foreach ($po->items as $i) {
                if (!$i->product) continue;
                $unit=$i->inventory_unit?:$i->unit;
                if ($unit!==$i->product->unit) {$unknown[]='unqualified_po_unit:'.$i->id;continue;}
                $lines[]=['product'=>$i->product,'quantity'=>max(0,round((float)($i->base_quantity??$i->quantity)-(float)($i->received_base_quantity??$i->received_quantity),3)),
                    'warehouse_id'=>$s->warehouse_id??$po->warehouse_id,'po_id'=>$po->id,'allocation'=>'single_linked_shipment_po_remainder'];
            }
        }
        $rows=[];
        foreach (collect($lines)->groupBy(fn($r)=>$r['product']->id.':'.($r['warehouse_id']??0)) as $group) {
            if($group->sum('quantity')<=0)continue;
            $first=$group->first();$p=$first['product'];$w=$first['warehouse_id'];
            $base=app(InventoryPlanningService::class)->decisionEvidence($p->id,['warehouse_id'=>$w]);
            $input=$base['input'];$poIds=$group->pluck('po_id')->filter()->unique()->all();
            $allocations=$group->filter(fn($r)=>$r['po_id'])->groupBy('po_id')->map(fn($g)=>round($g->sum('quantity'),3))->all();
            $otherIntelligence=\App\Models\ShipmentIntelligence::where('is_current',true)->where('shipment_id','!=',$s->id)
                ->whereHas('shipment',fn($q)=>$q->whereIn('purchase_order_id',array_column($input['stock']['incoming_schedule'],'purchase_order_id'))->orWhereHas('purchaseOrders',fn($o)=>$o->whereIn('purchase_orders.id',array_column($input['stock']['incoming_schedule'],'purchase_order_id'))))->limit(100)->get();
            $input['firm_incoming']=app(ShipmentArrivalTiming::class)->apply($input,$input['at_risk_purchase_orders'],$otherIntelligence->all(),$p->id,$w);
            // Remove this shipment's already-counted PO rows while computing the
            // requirement. No shipment quantity is appended to canonical stock.
            $left=$allocations;$input['firm_incoming']=array_values(array_filter(array_map(function($r)use(&$left){$po=$r['purchase_order_id']??null;if(isset($left[$po])){$take=min($left[$po],$r['quantity']);$r['quantity']-=$take;$left[$po]-=$take;}return $r;},$input['firm_incoming']),fn($r)=>$r['quantity']>0));
            $plan=app(InventoryPlanningMath::class)->calculate($input);
            $stock=$input['stock'];$need=$plan['stockout_date'];$threshold=null;
            foreach($plan['timeline'] as $day)if($day['baseline']<$plan['safety_stock']){$threshold=$day['date'];break;}
            $arrival=$eta['range_end'];
            $warehouseArrival=$eta['target']==='warehouse'?$arrival:null;
            // Port arrival cannot promise usable warehouse inventory. Unknown
            // inland/customs/receiving time remains exposed, not an on-time receipt.
            $exposed=$need&&(!$warehouseArrival||$warehouseArrival>$need);
            $alreadyCritical=$stock['available_to_promise']<=0&&$group->sum('quantity')>0;
            $shortage=$need&&$warehouseArrival?max(0,(int)ceil(Date::parse($need)->diffInDays(Date::parse($warehouseArrival),false))):null;
            $alternatives=[];
            if ($w&&($exposed||$alreadyCritical)) {
                $floor=max($input['minimum_safety'],$input['reorder_floor']);
                foreach(Warehouse::where('is_active',true)->whereKeyNot($w)->whereHas('stock',fn($q)=>$q->where('product_id',$p->id))->orderBy('id')->limit(15)->get() as $source){
                    $donor=app(InventorySnapshotService::class)->forProduct($p,null,$source->id);
                    $qty=max(0,$donor['available_to_promise']-$floor);
                    if ($qty<=0) continue;
                    $days=app(InventoryPlanningData::class)->transferLead($source->id,$w);
                    $alternatives[]=['type'=>'warehouse_transfer','source_warehouse_id'=>$source->id,'source_warehouse_name'=>$source->name,
                        'quantity'=>round($qty,3),'arrival'=>$days===null?null:today()->addDays((int)ceil($days))->toDateString(),
                        'qualification'=>'configured_donor_floor_requires_manager_review','url'=>'/warehouse-operations'];
                }
            }
            $other=array_values(array_filter($input['firm_incoming'],fn($r)=>isset($r['purchase_order_id'])&&(!$warehouseArrival||$r['expected_at']<$warehouseArrival)));
            $commitments=SalesOrderItem::with('order:id,order_number,customer_id,warehouse_id,requested_delivery_date')->where('product_id',$p->id)
                ->whereHas('order',fn($q)=>$q->whereNotNull('confirmed_at')->whereNotIn('status',['cancelled','delivered'])->when($w,fn($q)=>$q->where('warehouse_id',$w)))
                ->limit(50)->get()->map(fn($i)=>['order_id'=>$i->sales_order_id,'reference'=>$i->order?->order_number,
                    'remaining_quantity'=>max(0,round((float)$i->base_quantity-(float)$i->dispatched_quantity,3)),
                    'due'=>$i->order?->requested_delivery_date?->toDateString()])->filter(fn($r)=>$r['remaining_quantity']>0)->values()->all();
            $commitments=array_map(fn($r)=>$r+['exposed'=>(bool)(($exposed||$alreadyCritical)&&($alreadyCritical||($r['due']&&(!$warehouseArrival||$r['due']<$warehouseArrival))))],$commitments);
            $commitments=array_values(array_filter($commitments,fn($r)=>$r['exposed']));
            $rows[]=['product'=>$p->only('id','name','sku','unit'),'warehouse'=>$w?Warehouse::find($w)?->only('id','name'):null,
                'quantity'=>round($group->sum('quantity'),3),'po_ids'=>$poIds,'po_allocations'=>$allocations,'allocation'=>$first['allocation'],
                'stock'=>$stock,'forecast_available'=>(bool)$plan['daily'],'forecast_source'=>$plan['quality']['forecast_source'],
                'coverage_days'=>$plan['days_of_supply'],'safety_stock'=>$plan['safety_stock'],'safety_date'=>$threshold,
                'requirement_date'=>$need,'arrival'=>$arrival,'arrival_target'=>$eta['target'],
                'exposed'=>(bool)($exposed||$alreadyCritical),'critical'=>(bool)($alreadyCritical||($exposed&&$need<=today()->addDays(3)->toDateString())),
                'potential_shortage_days'=>$shortage,'warehouse_arrival_unknown'=>$eta['target']!=='warehouse',
                'timeline'=>array_slice($plan['timeline'],0,90),'alternative_incoming'=>$other,
                'transfer_options'=>array_slice($alternatives,0,3),'customer_orders'=>$commitments];
        }
        return ['products'=>$rows,'affected_products'=>count($rows),'exposed_products'=>count(array_filter($rows,fn($r)=>$r['exposed'])),
            'critical_products'=>count(array_filter($rows,fn($r)=>$r['critical'])),'unknowns'=>$unknown,
            'quantity_authority'=>'remaining_PO_quantity; shipment_allocation_describes_timing_not_additional_inventory'];
    }
}
