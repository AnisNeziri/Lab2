<?php
namespace App\Services;
use App\Models\{OrderChannel,OrderIntake,SalesOrder};
use Illuminate\Support\Facades\{Auth,DB};
class OrderHubReportingService {
    public function summary(string $from,string $to): array {
        $company=Auth::user()->company_id;
        $counts=DB::table('order_intakes as i')->leftJoin('sales_orders as o','o.id','=','i.sales_order_id')
            ->where('i.company_id',$company)->whereBetween('i.created_at',[$from.' 00:00:00',$to.' 23:59:59'])
            ->groupBy('i.order_channel_id')->selectRaw("i.order_channel_id, COUNT(*) as orders, SUM(CASE WHEN o.confirmed_at IS NOT NULL THEN 1 ELSE 0 END) as accepted, SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled, SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered, SUM(COALESCE(o.total_amount,0)) as order_value")->get()->keyBy('order_channel_id');
        $sales=DB::table('order_intakes as i')->join('outbound_dispatches as d','d.sales_order_id','=','i.sales_order_id')->where('i.company_id',$company)->whereBetween('d.dispatched_at',[$from.' 00:00:00',$to.' 23:59:59'])->groupBy('i.order_channel_id')->selectRaw('i.order_channel_id, SUM(d.total_amount) as dispatched_value')->pluck('dispatched_value','order_channel_id');
        $channels=OrderChannel::withCount(['intakes as pending'=>fn($q)=>$q->whereIn('state',['received','validated']),'intakes as failed'=>fn($q)=>$q->where('state','attention')])->withMax('intakes','last_success_at')->get();
        return ['from'=>$from,'to'=>$to,'currency'=>\App\Support\CompanyCurrency::current(),'channels'=>$channels->map(function($c)use($counts,$sales){$n=$counts[$c->id]??null;return ['id'=>$c->id,'name'=>$c->name,'orders'=>(int)($n->orders??0),'accepted'=>(int)($n->accepted??0),'cancelled'=>(int)($n->cancelled??0),'delivered'=>(int)($n->delivered??0),'order_value'=>\App\Support\Money::normalize($n->order_value??0),'dispatched_value'=>\App\Support\Money::normalize($sales[$c->id]??0),'health'=>!$c->enabled?'disabled':($c->failed?'degraded':($c->intakes_max_last_success_at?'connected':'offline')),'pending'=>$c->pending,'failed'=>$c->failed,'last_success'=>$c->intakes_max_last_success_at];})->all()];
    }
    public function demand(array $filters=[]){
        return DB::table('sales_order_items as l')->join('sales_orders as o','o.id','=','l.sales_order_id')->leftJoin('order_intakes as i','i.sales_order_id','=','o.id')->where('o.company_id',Auth::user()->company_id)
            ->when($filters['product_id']??null,fn($q,$v)=>$q->where('l.product_id',$v))->orderBy('l.id')
            ->select(['o.id as order_id','i.order_channel_id','l.product_id','l.unit','l.base_quantity','l.reserved_quantity','l.dispatched_quantity','l.delivered_quantity','l.returned_quantity','o.order_date','o.requested_delivery_date','o.completed_at','o.status'])->paginate(50);
    }
}
