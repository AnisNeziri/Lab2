<?php
namespace App\Services;
use App\Models\{SalesOrder,SalesOrderItem,PickTask,OutboundDispatch,OutboundReturn,OperationalException};
use Illuminate\Support\Facades\Auth;

class OutboundReportingService
{
    public function metrics():array
    {
        $from=now()->subDays(90);$pickSeconds=0;$pickCount=0;$orderSeconds=0;$orderCount=0;$onTime=0;$dueCount=0;$dispatchDue=0;$dispatchOnTime=0;
        foreach(PickTask::query()->whereNotNull('completed_at')->where('completed_at','>=',$from)->cursor() as $t){if($t->started_at){$pickSeconds+=$t->started_at->diffInSeconds($t->completed_at);$pickCount++;}}
        foreach(SalesOrder::query()->where('status','delivered')->where('completed_at','>=',$from)->cursor() as $o){$orderSeconds+=$o->created_at->diffInSeconds($o->completed_at);$orderCount++;if($o->requested_delivery_date){$dueCount++;if($o->completed_at->toDateString()<=$o->requested_delivery_date->toDateString())$onTime++;}}
        foreach(OutboundDispatch::query()->with('order:id,requested_delivery_date')->where('dispatched_at','>=',$from)->lazy(200) as $d){if($d->order->requested_delivery_date){$dispatchDue++;if($d->dispatched_at->toDateString()<=$d->order->requested_delivery_date->toDateString())$dispatchOnTime++;}}
        $alloc=\App\Models\OutboundAllocation::query()->where('created_at','>=',$from);$picked=(clone $alloc)->where(fn($q)=>$q->where('picked_quantity','>',0)->orWhereNotNull('exception_reason'))->count();$short=(clone $alloc)->whereNotNull('exception_reason')->count();
        $delivered=SalesOrderItem::query()->where('created_at','>=',$from)->sum('delivered_quantity');$returned=SalesOrderItem::query()->where('created_at','>=',$from)->sum('returned_quantity');
        return ['from'=>$from->toDateString(),'to'=>now()->toDateString(),'average_pick_minutes'=>$pickCount?round($pickSeconds/$pickCount/60,1):null,'average_fulfillment_hours'=>$orderCount?round($orderSeconds/$orderCount/3600,1):null,'on_time_delivery_percent'=>$dueCount?round(100*$onTime/$dueCount,1):null,'on_time_dispatch_percent'=>$dispatchDue?round(100*$dispatchOnTime/$dispatchDue,1):null,'short_pick_percent'=>$picked?round(100*$short/$picked,1):null,'pick_accuracy_percent'=>$picked?round(100*max(0,$picked-$short)/$picked,1):null,'return_percent'=>$delivered?round(100*$returned/$delivered,1):null,'completed_orders'=>$orderCount,'completed_picks'=>$pickCount];
    }
    public function context(string $type,int $id):array
    {
        $orders=SalesOrder::query()->when($type==='customer',fn($q)=>$q->where('customer_id',$id))->when($type==='product',fn($q)=>$q->whereHas('items',fn($i)=>$i->where('product_id',$id)))->when($type==='warehouse',fn($q)=>$q->whereHas('allocations',fn($a)=>$a->where('warehouse_id',$id)));
        $items=SalesOrderItem::query()->whereIn('sales_order_id',(clone $orders)->select('id'))->when($type==='product',fn($q)=>$q->where('product_id',$id));
        $open=(clone $items)->whereHas('order',fn($q)=>$q->whereNotIn('status',['delivered','cancelled']));
        $summary=$type==='product'?[
            'open_demand'=>OutboundService::q((clone $open)->selectRaw('COALESCE(SUM(base_quantity - dispatched_quantity),0) as amount')->value('amount')),
            'reserved'=>OutboundService::q((clone $open)->sum('reserved_quantity')),
            'allocated'=>OutboundService::q((clone $open)->selectRaw('COALESCE(SUM(allocated_quantity - dispatched_quantity),0) as amount')->value('amount')),
            'picked'=>OutboundService::q((clone $open)->selectRaw('COALESCE(SUM(picked_quantity - dispatched_quantity),0) as amount')->value('amount')),
            'returned'=>OutboundService::q((clone $items)->sum('returned_quantity')),
        ]:[
            'open_orders'=>(clone $orders)->whereNotIn('status',['delivered','cancelled'])->count(),
            'open_tasks'=>PickTask::query()->whereIn('sales_order_id',(clone $orders)->select('id'))->when($type==='warehouse',fn($q)=>$q->where('warehouse_id',$id))->whereNotIn('status',['completed','cancelled'])->count(),
            'open_returns'=>OutboundReturn::query()->whereIn('sales_order_id',(clone $orders)->select('id'))->whereNotIn('status',['resolved','cancelled'])->count(),
        ];
        return ['orders'=>$orders->with('customer:id,name')->latest()->limit(20)->get(),'url'=>'/fulfillment?'.$type.'_id='.$id,
            'summary'=>$summary];
    }
    public function refreshExceptions(): void
    {
        SalesOrder::query()->whereNotIn('status',['delivered','cancelled'])->whereDate('requested_delivery_date','<',now()->toDateString())->chunkById(100,function($orders){foreach($orders as $o)OperationalException::query()->firstOrCreate(['company_id'=>$o->company_id,'exception_key'=>'outbound-late:'.$o->id],['exception_type'=>'order_overdue','entity_type'=>'SalesOrder','entity_id'=>$o->id,'severity'=>'warning','status'=>'open','detected_at'=>now(),'description'=>'Sales order '.$o->order_number.' is past its requested delivery date.','next_action'=>['url'=>'/fulfillment?order='.$o->id]]);});
        OperationalException::query()->where('exception_type','order_overdue')->where('entity_type','SalesOrder')->whereIn('entity_id',SalesOrder::query()->whereIn('status',['delivered','cancelled'])->select('id'))->where('status','open')->update(['status'=>'resolved','resolved_at'=>now(),'resolved_by'=>Auth::id()]);
    }
}
