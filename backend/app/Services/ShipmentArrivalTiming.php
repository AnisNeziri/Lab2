<?php
namespace App\Services;

/** Re-times existing PO rows; it cannot create additional physical quantity. */
final class ShipmentArrivalTiming {
    public function apply(array $input,array $riskIds,array $snapshots,int $product,?int $warehouse):array {
        $timed=[];$adjusted=[];
        foreach($snapshots as $r){
            if($r->checked_at->lt(now()->subHours(config('shipment_intelligence.prediction_max_age_hours'))))continue;
            foreach($r->impact['products'] as $p){
                if($p['product']['id']!==$product||($warehouse&&($p['warehouse']['id']??null)!==$warehouse))continue;
                $allocations=$p['po_allocations']??(count($p['po_ids'])===1?[$p['po_ids'][0]=>$p['quantity']]:[]);
                foreach($allocations as $po=>$quantity){$adjusted[$po]=true;
                    if($r->eta['target']==='warehouse'&&$r->eta['range_end']&&$r->eta['range_end']>=today()->toDateString())$timed[$po][]=['quantity'=>$quantity,'expected_at'=>$r->eta['range_end']];
                }
            }
        }
        $firm=[];
        foreach($input['stock']['incoming_schedule'] as $row){$po=$row['purchase_order_id'];
            if(isset($adjusted[$po])){
                $remaining=(float)$row['quantity'];foreach($timed[$po]??[] as $arrival){$qty=min($remaining,(float)$arrival['quantity']);if($qty>0)$firm[]=['purchase_order_id'=>$po,'quantity'=>$qty,'expected_at'=>$arrival['expected_at'],'timing_source'=>'shipment_intelligence'];$remaining-=$qty;}
                if($remaining>0&&!in_array($po,$riskIds,true)&&$row['expected_at']&&$row['expected_at']>=today()->toDateString())$firm[]=array_replace($row,['quantity'=>$remaining]);
            }elseif(!in_array($po,$riskIds,true)&&$row['expected_at']&&$row['expected_at']>=today()->toDateString())$firm[]=$row;
        }
        foreach($input['firm_incoming'] as $row)if(!isset($row['purchase_order_id']))$firm[]=$row;
        return $firm;
    }
}
