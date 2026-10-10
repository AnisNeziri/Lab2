<?php

namespace App\Services;

use App\Models\{Shipment,GoodsReceipt,LandedCost,ShipmentItem};
use Illuminate\Support\Facades\Auth;

/** Read-only presentation. PO receipts are never attributed to a shipment without a receipt link. */
final class ShipmentCargoPresentation
{
    public function forShipment(Shipment $shipment): array
    {
        $can=fn($p)=>app(PermissionService::class)->roleHasPermission(Auth::user()->role,$p);
        if(!$can('purchase_orders.view'))return ['restricted'=>true];
        $productFields='id,name,sku,unit,weight_kg,volume_m3,category_id,image_data,image_mime';
        $shipment->load(['purchaseOrder.items.product:'.$productFields,'purchaseOrders.items.product:'.$productFields,'items.product:'.$productFields,'purchaseOrder.items.product.category','purchaseOrders.items.product.category']);
        $shipment->loadMissing(['purchaseOrder.supplier','purchaseOrder.warehouse','purchaseOrders.supplier','purchaseOrders.warehouse','items.purchaseOrderItem','warehouse','supplier']);
        $orders=$shipment->purchaseOrders->concat($shipment->purchaseOrder?[$shipment->purchaseOrder]:[])->unique('id');
        $poItemIds=$orders->flatMap(fn($po)=>$po->items->pluck('id'))->all();
        $otherAllocations=ShipmentItem::whereIn('purchase_order_item_id',$poItemIds)->where('shipment_id','!=',$shipment->id)->with('shipment:id,tracking_number,archived_at')->get()->groupBy('purchase_order_item_id');
        $totals=[];$groups=[];$productIds=[];
        foreach($orders as $po){
            $rows=[];
            foreach($po->items as $item){
                $allocations=$shipment->items->where('purchase_order_item_id',$item->id);
                $byUnit=$allocations->groupBy('unit')->map(fn($as,$unit)=>['unit'=>$unit,'quantity'=>round($as->sum(fn($a)=>(float)($a->planned_quantity??$a->quantity)),3)])->values();
                $quantity=$byUnit->count()===1?$byUnit->first()['quantity']:null;
                $loaded=$byUnit->count()===1&&$allocations->every(fn($a)=>$a->loaded_quantity!==null)?round($allocations->sum('loaded_quantity'),3):null;
                $unit=$allocations->first()?->unit??$item->unit;
                $productIds[]=$item->product_id;
                foreach($byUnit as $amount)$totals[$amount['unit']]=round(($totals[$amount['unit']]??0)+$amount['quantity'],3);
                $others=$otherAllocations->get($item->id,collect());
                $split=$others->groupBy(fn($a)=>$a->shipment_id.':'.$a->unit)->map(fn($as)=>['reference'=>$as->first()->shipment?->tracking_number,'quantity'=>round($as->sum(fn($a)=>(float)($a->planned_quantity??$a->quantity)),3),'unit'=>$as->first()->unit,'url'=>'/shipments/my-shipments?shipment='.$as->first()->shipment_id])->values()->all();
                $all=$allocations->concat($others);
                $unallocated=$all->every(fn($a)=>$a->unit===$item->unit)?round((float)$item->quantity-$all->sum(fn($a)=>(float)($a->planned_quantity??$a->quantity)),3):null;
                if($unallocated<0)$unallocated=null;
                $rows[]=['product_id'=>$item->product_id,'name'=>$item->product?->name??$item->description,'sku'=>$item->product?->sku,'image_url'=>$item->product?->image_url,
                    'category'=>$item->product?->category?->name,'ordered'=>$item->quantity,'received'=>$item->received_quantity,'order_unit'=>$item->unit,
                    'allocated'=>$quantity,'allocation_quantities'=>$byUnit->all(),'unallocated'=>$unallocated,'loaded'=>$loaded,'unit'=>$unit,'destination'=>$shipment->warehouse?->name??$po->warehouse?->name,
                    'status'=>(float)$item->received_quantity>=(float)$item->quantity?'received':((float)$item->received_quantity>0?'partially_received':($allocations->isNotEmpty()&&in_array($shipment->status,['in_transit','departed','arrived','customs','delivered'])?'in_transit':'ordered')),
                    'receipt_scope'=>'purchase_order','other_shipments'=>$split,'url'=>'/products?product='.$item->product_id];
            }
            $receipts=$can('inventory.view')?GoodsReceipt::where('purchase_order_id',$po->id)->with('items')->orderByDesc('received_at')->get()->map(fn($r)=>['reference'=>$r->receipt_number,'received_at'=>$r->received_at?->toIso8601String(),'status'=>$r->status,'url'=>'/warehouse-operations?receipt='.$r->id,
                'quantities'=>$r->items->groupBy('inventory_unit')->map(fn($items,$unit)=>['unit'=>$unit,'accepted'=>round($items->sum('accepted_base_quantity'),3),'damaged'=>round($items->sum('damaged_base_quantity'),3)])->values()->all()])->all():[];
            $groups[]=['reference'=>$po->po_number,'supplier'=>$po->supplier?->name,'destination'=>$shipment->warehouse?->name??$po->warehouse?->name,'status'=>$po->status,
                'purchase_value'=>$can('analytics.finance')?$po->total_amount:null,'currency'=>$po->currency,'value_scope'=>'purchase_order','url'=>'/purchase-orders?po='.$po->id,'items'=>$rows,'receipts'=>$receipts];
        }
        // Cargo can be recorded without a PO; do not silently omit those allocations.
        $unlinked=$shipment->items->whereNull('purchase_order_item_id');
        if($unlinked->isNotEmpty()){
            $rows=$unlinked->map(function($a)use(&$totals,&$productIds,$shipment){$quantity=(float)($a->planned_quantity??$a->quantity);$totals[$a->unit]=round(($totals[$a->unit]??0)+$quantity,3);$productIds[]=$a->product_id;return ['product_id'=>$a->product_id,'name'=>$a->product?->name??$a->description,'sku'=>$a->product?->sku,'ordered'=>null,'received'=>null,'order_unit'=>$a->unit,'allocated'=>$quantity,'loaded'=>$a->loaded_quantity,'unit'=>$a->unit,'destination'=>$shipment->warehouse?->name,'status'=>'ordered','receipt_scope'=>null,'other_shipments'=>[],'url'=>$a->product_id?'/products?product='.$a->product_id:null];})->all();
            $groups[]=['reference'=>null,'supplier'=>$shipment->supplier?->name,'destination'=>$shipment->warehouse?->name,'purchase_value'=>null,'currency'=>null,'url'=>null,'items'=>$rows,'receipts'=>[]];
        }
        $costs=$can('landed_costs.manage')?LandedCost::where('shipment_id',$shipment->id)->where('status','posted')->with('allocations.goodsReceiptItem.product:id,name')->get()->map(fn($c)=>['reference'=>$c->reference_number,'type'=>$c->cost_type,'amount'=>$c->base_currency_amount,'currency'=>$c->base_currency,'url'=>'/operations-center?tab=landed',
            'allocations'=>$c->allocations->map(fn($a)=>['product'=>$a->goodsReceiptItem?->product?->name,'amount'=>$a->allocated_amount,'purchase_unit_cost'=>$a->base_purchase_unit_cost_snapshot,'final_unit_cost'=>$a->final_unit_cost])->all()])->all():[];
        return ['groups'=>$groups,'product_count'=>count(array_unique(array_filter($productIds))),'quantities'=>collect($totals)->map(fn($quantity,$unit)=>['quantity'=>$quantity,'unit'=>$unit])->values()->all(),
            'allocation_known'=>$shipment->items->isNotEmpty(),'receipts_scope'=>'purchase_order','costs'=>$costs];
    }
}
