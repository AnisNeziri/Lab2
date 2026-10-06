<?php
namespace App\Observers;
use App\Models\{Shipment,ShipmentMilestone,ShipmentContainer,ShipmentItem,ShipmentHistory,GoodsReceipt,PurchaseOrder};
use App\Services\ShipmentIntelligenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{DB,Log,Auth,Schema};

/** Only meaningful milestone/schedule changes are retained, never raw AIS position streams. */
final class ShipmentIntelligenceObserver {
    public function saved(Model $entity): void {
        $this->capture($entity);
    }
    public function capture(Model $entity,bool $existing=false): void {
        $fields=match(true){
            $entity instanceof Shipment=>['status','eta','departed_at','arrival_date','mmsi','vessel_name','origin_port','destination_port','warehouse_id','supplier_id','purchase_order_id','transport_mode'],
            $entity instanceof ShipmentMilestone=>['milestone_type','scope_key','planned_at','estimated_at','actual_at','source','status'],
            $entity instanceof ShipmentContainer=>['status','etd','eta','actual_departure','actual_arrival','vessel_name'],
            $entity instanceof GoodsReceipt=>['status','received_at','purchase_order_id','warehouse_id','receipt_number'],
            default=>[]};
        if(!$existing&&$fields&&!$entity->wasRecentlyCreated&&!array_intersect($fields,array_keys($entity->getChanges())))return;
        if(!Schema::hasTable('shipment_intelligence'))return;
        $values=$fields?$entity->only($fields):[];$company=(int)$entity->company_id;$type=class_basename($entity);$entityId=$entity->id;
        $ids=$entity instanceof Shipment?[$entityId]:(!empty($entity->shipment_id)?[$entity->shipment_id]:[]);
        if($entity instanceof GoodsReceipt||$entity instanceof PurchaseOrder){$po=$entity instanceof PurchaseOrder?$entity->id:$entity->purchase_order_id;
            $ids=Shipment::withoutGlobalScopes()->where('company_id',$company)->where(fn($q)=>$q->where('purchase_order_id',$po)->orWhereHas('purchaseOrders',fn($r)=>$r->where('purchase_orders.id',$po)))->pluck('id')->all();}
        $capture=static function()use($ids,$company,$type,$entityId,$values){try{
            foreach($ids as $id){
                if(!$values){ShipmentIntelligenceService::invalidate($company,$id);continue;}
                $latest=ShipmentHistory::withoutGlobalScopes()->where('company_id',$company)->where('shipment_id',$id)->where('event_type','intelligence.observation')->where('metadata->entity_type',$type)->where('metadata->entity_id',$entityId)->latest('id')->first();
                if($latest&&($latest->metadata['values']??[])==json_decode(json_encode($values),true))continue;
                ShipmentIntelligenceService::invalidate($company,$id);
                ShipmentHistory::create(['company_id'=>$company,'shipment_id'=>$id,'user_id'=>Auth::id(),'event_type'=>'intelligence.observation',
                    'description'=>'Recorded logistics milestone/schedule observation. Previous observations remain unchanged.',
                    'metadata'=>['entity_type'=>$type,'entity_id'=>$entityId,'values'=>$values,'known_at'=>now()->toIso8601String(),'revision_of'=>$latest?->id], 'created_at'=>now()]);
            }
        }catch(\Throwable $e){Log::warning('Logistics observation deferred.',['entity_type'=>$type,'entity_id'=>$entityId]);}};
        DB::transactionLevel()>0?DB::afterCommit($capture):$capture();
    }
    public function deleted(Model $entity): void { if(!empty($entity->shipment_id))ShipmentIntelligenceService::invalidate((int)$entity->company_id,(int)$entity->shipment_id); }
}
