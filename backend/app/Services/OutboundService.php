<?php
namespace App\Services;

use App\Models\{SalesOrder,SalesOrderItem,OutboundAllocation,PickTask,PickWave,OutboundPackage,OutboundPackageItem,OutboundDispatch,OutboundReturn,Product,Customer,Warehouse,WarehouseStock,InventoryTraceBalance,InventoryLot,OperationalException};
use App\Support\{Money,RequestFingerprint,CompanyCurrency,BarcodeIdentity};
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Validation\ValidationException;

class OutboundService
{
    public static function q(mixed $value): string { return Money::normalizeDecimal($value,3); }
    private function add($a,$b): string { return self::q(BigDecimal::of((string)$a)->plus((string)$b)); }
    private function sub($a,$b): string { return self::q(BigDecimal::of((string)$a)->minus((string)$b)); }
    private function positive($v): bool { return Money::compareDecimal($v,'0')>0; }
    private function sum($rows,string $field): string { return $rows->reduce(fn($total,$row)=>$this->add($total,$row[$field]),'0.000'); }
    private function fail(string $message): never { throw ValidationException::withMessages(['fulfillment'=>[$message]]); }

    public function orders(array $filters=[])
    {
        return SalesOrder::query()->with('customer:id,name')->withCount(['tasks','packages','dispatches'])
            ->when($filters['search']??null,fn($q,$v)=>$q->where(fn($s)=>$s->where('order_number','like','%'.$v.'%')->orWhereHas('customer',fn($c)=>$c->where('name','like','%'.$v.'%'))))
            ->when($filters['status']??null,fn($q,$v)=>$q->where('status',$v))
            ->when($filters['view']??null,function($q,$view){
                if($view==='returns')$q->whereHas('returns',fn($r)=>$r->whereNotIn('status',['resolved','cancelled']));
                if($view==='late')$q->whereNotIn('status',['delivered','cancelled'])->whereDate('requested_delivery_date','<',now()->toDateString());
            })
            ->when($filters['product_id']??null,fn($q,$v)=>$q->whereHas('items',fn($i)=>$i->where('product_id',$v)))
            ->when($filters['wave']??null,fn($q,$v)=>$q->whereHas('tasks',fn($t)=>$t->where('pick_wave_id',$v)))
            ->when($filters['customer_id']??null,fn($q,$v)=>$q->where('customer_id',$v))
            ->when($filters['warehouse_id']??null,fn($q,$v)=>$q->where(fn($w)=>$w->where('warehouse_id',$v)->orWhereHas('allocations',fn($a)=>$a->where('warehouse_id',$v))))
            ->orderBy('priority')->orderByRaw('CASE WHEN requested_delivery_date IS NULL THEN 1 ELSE 0 END')->orderBy('requested_delivery_date')->orderBy('order_date')->orderBy('id')
            ->paginate(min(100,max(1,(int)($filters['per_page']??20))));
    }

    public function detail(SalesOrder $order): array
    {
        $order->load(['customer','items.product.units','allocations.item.product','allocations.warehouse','allocations.location','allocations.lot','tasks.assignee','packages.items','dispatches.packages.items','returns']);
        $snapshots=app(InventorySnapshotService::class)->forProducts($order->items->pluck('product')->unique('id'),$order->requested_delivery_date?->toDateString());
        foreach($order->items->groupBy('product_id') as $productId=>$lines){
            $needed=$this->sub($this->sum($lines,'base_quantity'),$this->add($this->sum($lines,'dispatched_quantity'),$this->sum($lines,'reserved_quantity')));
            $snapshot=$snapshots[$productId];
            $snapshot['expected_availability_date']=app(InventorySnapshotService::class)->expectedAvailabilityDate($snapshot,$needed,$order->confirmed_at?$needed:0);
            $snapshots[$productId]=$snapshot;
        }
        return [...$order->toArray(),'availability'=>$snapshots,'credit'=>app(OutboundCreditService::class)->context($order),
            'approval'=>$order->approval_request_id?\App\Models\ApprovalRequest::query()->find($order->approval_request_id):null];
    }

    public function create(array $data): SalesOrder
    {
        return DB::transaction(function()use($data){
            DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();
            $fp=RequestFingerprint::make($data,['idempotency_key']);
            if($old=SalesOrder::query()->where('idempotency_key',$data['idempotency_key'])->first()){
                if($old->request_fingerprint!==$fp)$this->fail('This request key was used for a different order.'); return $old;
            }
            if(!empty($data['customer_id']))Customer::query()->findOrFail($data['customer_id']);
            elseif(empty($data['guest_customer']['name'])||($data['payment_type']??'cash')!=='cash')$this->fail('A customer is required unless this is an identified cash guest order.');
            if(!empty($data['warehouse_id']))Warehouse::query()->findOrFail($data['warehouse_id']);
            $currency=CompanyCurrency::forCompanyId(Auth::user()->company_id);
            if(!empty($data['currency'])&&$data['currency']!==$currency)$this->fail('Use company currency until an explicit order exchange-rate snapshot is supported.');
            $order=SalesOrder::create(['company_id'=>Auth::user()->company_id,'customer_id'=>$data['customer_id'],'order_number'=>'SO-'.now()->format('Y').'-'.str_pad((string)(SalesOrder::query()->count()+1),6,'0',STR_PAD_LEFT),
                'order_date'=>$data['order_date'],'requested_delivery_date'=>$data['requested_delivery_date']??null,'priority'=>$data['priority']??2,'currency'=>$currency,'payment_type'=>$data['payment_type']??'cash','warehouse_id'=>$data['warehouse_id']??null,'notes'=>$data['notes']??null,'created_by'=>Auth::id(),'status'=>'draft','idempotency_key'=>$data['idempotency_key'],'request_fingerprint'=>$fp]);
            $this->replaceItems($order,$data['items']);
            if(empty($data['hub_intake_id']))app(OrderHubService::class)->attachManual($order->fresh(),$data);
            $this->event($order,'sales_order.created'); return $order->fresh();
        });
    }

    private function replaceItems(SalesOrder $order,array $lines): void
    {
        if(!$lines)$this->fail('Add at least one product.');
        $order->items()->delete(); $total='0';
        foreach($lines as $line){
            $product=Product::query()->with('units')->findOrFail($line['product_id']);
            $quantity=self::q($line['quantity']); if(!$this->positive($quantity))$this->fail('Quantity must be positive.');
            $unit=$line['unit']??$product->unit; $factor='1';
            if(strtolower($unit)!==strtolower($product->unit)){
                $pack=$product->units->first(fn($u)=>$u->is_active&&strtolower($u->code)===strtolower($unit)&&$u->conversion_mode==='fixed');
                if(!$pack)$this->fail('Choose an active fixed pack conversion.'); $factor=(string)$pack->factor_to_base;
            }
            $base=self::q(BigDecimal::of($quantity)->multipliedBy($factor));
            app(UnitConversionService::class)->assertPrecision((float)$base,$product->unit);
            $price=Money::normalize($line['unit_price']); if(Money::compare($price,'0')<0)$this->fail('Price cannot be negative.');
            $value=Money::multiply($quantity,$price); $total=Money::add($total,$value);
            $order->items()->create(['company_id'=>$order->company_id,'product_id'=>$product->id,'unit'=>$unit,'quantity'=>$quantity,'base_quantity'=>$base,'conversion_factor'=>$factor,'unit_price'=>$price,'line_total'=>$value]);
        }
        $order->update(['total_amount'=>$total]);
    }

    public function action(SalesOrder $order,string $action,array $data): array
    {
        return DB::transaction(function()use($order,$action,$data){
            DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();
            $order=SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $key=$data['idempotency_key']; $fp=RequestFingerprint::make(['order'=>$order->id,'action'=>$action,...$data],['idempotency_key']);
            $old=DB::table('outbound_actions')->where('company_id',$order->company_id)->where('idempotency_key',$key)->first();
            if($old){if($old->fingerprint!==$fp)$this->fail('This request key belongs to a different action.'); return $this->detail($order);}
            if($order->status==='cancelled')$this->fail('This order is cancelled.');
            match($action){
                'edit'=>$this->edit($order,$data), 'confirm'=>$this->confirm($order),
                'credit-override'=>app(OutboundCreditService::class)->request($order,$data['reason']??'Sales order credit override'),
                'reserve'=>$this->reserve($order,$data), 'release'=>$this->release($order,$data),
                'allocate'=>$this->allocate($order,$data), 'assign'=>$this->assign($order,$data), 'pick'=>$this->pick($order,$data),
                'pack'=>$this->pack($order,$data), 'unpack'=>$this->unpack($order,$data),
                'dispatch'=>$this->dispatch($order,$data), 'delivery'=>$this->delivery($order,$data),
                'return'=>$this->returnAction($order,$data), 'cancel'=>$this->cancel($order),
                default=>$this->fail('Unknown fulfillment action.')
            };
            $this->sync($order);
            DB::table('outbound_actions')->insert(['company_id'=>$order->company_id,'sales_order_id'=>$order->id,'action'=>$action,'idempotency_key'=>$key,'fingerprint'=>$fp,'created_at'=>now(),'updated_at'=>now()]);
            return $this->detail($order->fresh());
        });
    }

    private function edit(SalesOrder $o,array $d): void
    {
        if($o->confirmed_at)$this->fail('Only draft orders can be edited. Cancel outstanding demand and create a revision after confirmation.');
        if(!empty($d['customer_id']))Customer::query()->findOrFail($d['customer_id']);
        elseif(empty($d['guest_customer']['name'])||$d['payment_type']!=='cash')$this->fail('A customer is required unless this is an identified cash guest order.');
        if(!empty($d['warehouse_id']))Warehouse::query()->findOrFail($d['warehouse_id']);
        $o->update(collect($d)->only(['customer_id','order_date','requested_delivery_date','priority','notes','warehouse_id','payment_type'])->all());
        $this->replaceItems($o,$d['items']); $o->update(['approval_request_id'=>null]);
        $this->event($o,'sales_order.updated',['reason'=>$d['reason']??null],$d['idempotency_key']);
    }

    private function confirm(SalesOrder $o): void
    {
        if($o->confirmed_at)return;
        app(OrderHubService::class)->assertConfirmable($o);
        app(OutboundCreditService::class)->authorize($o);
        app(OutboundCreditService::class)->consume($o);
        $o->update(['confirmed_at'=>now(),'status'=>'ready_to_allocate','committed_amount'=>$o->payment_type==='cash'?'0':$o->total_amount]);
        $this->event($o,'sales_order.confirmed');
    }

    public function candidates(SalesOrderItem $item): array
    {
        $o=$item->order; $p=$item->product; $rows=[];
        if($p->tracking_mode && $p->tracking_mode!=='none'){
            $balances=InventoryTraceBalance::query()->with(['lot','warehouse:id,name','location:id,name,path'])->where('stock_state','available')->where('quantity','>',0)->whereHas('warehouse',fn($q)=>$q->where('is_active',true))->where(fn($q)=>$q->whereNull('location_id')->orWhereHas('location',fn($l)=>$l->where('is_active',true)))
                ->whereHas('lot',fn($q)=>$q->where('product_id',$p->id)->where('status','active')->where(fn($x)=>$x->whereNull('expiry_at')->orWhereDate('expiry_at','>=',now()->toDateString())))
                ->when($o->warehouse_id,fn($q,$id)=>$q->where('warehouse_id',$id))->get()
                ->sortBy(fn($b)=>($b->lot->expiry_at?->format('Y-m-d')??'9999-12-31').'|'.$b->lot->created_at->format('YmdHis').'|'.str_pad($b->id,12,'0',STR_PAD_LEFT));
            foreach($balances as $b)$rows[]=['warehouse_id'=>$b->warehouse_id,'location_id'=>$b->location_id,'inventory_lot_id'=>$b->inventory_lot_id,'warehouse'=>$b->warehouse->name,'location'=>$b->location?->path,'lot'=>$b->lot->lot_number?:$b->lot->serial_number,'expiry'=>$b->lot->expiry_at?->toDateString(),'available'=>$b->quantity];
        }else{
            $balances=WarehouseStock::query()->with(['warehouse:id,name','location:id,name,path'])->where('product_id',$p->id)->where('available_quantity','>',0)->whereHas('warehouse',fn($q)=>$q->where('is_active',true))->where(fn($q)=>$q->whereNull('location_id')->orWhereHas('location',fn($l)=>$l->where('is_active',true)))->when($o->warehouse_id,fn($q,$id)=>$q->where('warehouse_id',$id))->orderBy('created_at')->orderBy('id')->get();
            foreach($balances as $b)$rows[]=['warehouse_id'=>$b->warehouse_id,'location_id'=>$b->location_id,'inventory_lot_id'=>null,'warehouse'=>$b->warehouse->name,'location'=>$b->location?->path,'lot'=>null,'expiry'=>null,'available'=>$b->available_quantity];
        }
        return $rows;
    }

    private function reserve(SalesOrder $o,array $d): void
    {
        if(!$o->confirmed_at)$this->fail('Confirm the order before reserving inventory.');
        app(OutboundCreditService::class)->authorize($o);
        foreach($o->items()->with('product')->get() as $item){
            Product::query()->lockForUpdate()->findOrFail($item->product_id);
            $existing=$item->allocations()->where('status','!=','released')->sum('quantity'); $needed=$this->sub($item->base_quantity,$existing);
            if(!$this->positive($needed))continue;
            $candidates=$this->candidates($item);
            if(isset($d['allocations'])){
                if(empty($d['reason']))$this->fail('Explain the manual allocation override.');
                $selected=array_values(array_filter($d['allocations'],fn($x)=>(int)$x['sales_order_item_id']===$item->id));
                foreach($selected as &$s){$match=collect($candidates)->first(fn($c)=>(int)$c['warehouse_id']===(int)$s['warehouse_id']&&(int)$c['location_id']===(int)($s['location_id']??0)&&(int)$c['inventory_lot_id']===(int)($s['inventory_lot_id']??0));if(!$match||Money::compareDecimal($s['quantity'],$match['available'])>0)$this->fail('The selected location or lot does not have enough eligible stock.');$s=[...$match,'available'=>$s['quantity']];}unset($s);$candidates=$selected;
            }
            foreach($candidates as $c){
                if(!$this->positive($needed))break;
                $qty=Money::compareDecimal($needed,$c['available'])<0?$needed:self::q($c['available']); if(!$this->positive($qty))continue;
                app(UnitConversionService::class)->assertPrecision((float)$qty,$item->product->unit);
                $a=OutboundAllocation::create(['company_id'=>$o->company_id,'sales_order_id'=>$o->id,'sales_order_item_id'=>$item->id,'warehouse_id'=>$c['warehouse_id'],'location_id'=>$c['location_id'],'inventory_lot_id'=>$c['inventory_lot_id'],'quantity'=>$qty,'created_by'=>Auth::id()]);
                $this->transition($a,'available','reserved',$qty,'reserve-'.$a->id);
                $needed=$this->sub($needed,$qty); $this->event($o,'inventory.reserved',['allocation_id'=>$a->id,'quantity'=>$qty,'manual_override_reason'=>$d['reason']??null],(string)$a->id);
            }
            if($this->positive($needed))$this->exception($o,'allocation_incomplete','Not enough eligible stock for '.$item->product->name,['item_id'=>$item->id,'short_quantity'=>$needed]);
        }
    }

    private function transition(OutboundAllocation $a,string $from,string $to,$qty,string $key): void
    {
        app(StockMovementService::class)->transitionState(['product_id'=>$a->item->product_id,'warehouse_id'=>$a->warehouse_id,'location_id'=>$a->location_id,'from_state'=>$from,'to_state'=>$to,'quantity'=>$qty,'trace_allocations'=>$this->trace($a,$qty),'source_type'=>'sales_order','source_id'=>$a->sales_order_id,'reason'=>'Outbound '.$key,'idempotency_key'=>$key]);
    }
    private function trace(OutboundAllocation $a,$qty): array { return $a->inventory_lot_id?[['inventory_lot_id'=>$a->inventory_lot_id,'quantity'=>$qty]]:[]; }

    private function release(SalesOrder $o,array $d): void
    {
        $allocations=$o->allocations()->where('status','!=','released')->when($d['allocation_id']??null,fn($q,$id)=>$q->where('id',$id))->get();
        foreach($allocations as $a){
            $unpicked=$this->sub($a->quantity,$a->picked_quantity);if(!$this->positive($unpicked))continue;
            $this->transition($a,'reserved','available',$unpicked,'release-'.$a->id.'-'.str_replace('.','_',$a->quantity));
            $a->update($this->positive($a->picked_quantity)?['quantity'=>$a->picked_quantity]:['status'=>'released']);$this->event($o,'inventory.released',['allocation_id'=>$a->id,'quantity'=>$unpicked],(string)$a->id);}
        foreach($o->tasks as $t)if(!$t->allocations()->where('status','!=','released')->exists())$t->update(['status'=>'cancelled']);
    }
    private function cancel(SalesOrder $o): void
    {
        if($o->items()->where('picked_quantity','>',0)->exists())$this->fail('Picked inventory must be reconciled before cancellation; dispatched inventory must use the return workflow.');
        $this->release($o,[]);$o->update(['status'=>'cancelled','committed_amount'=>'0']);$this->event($o,'sales_order.cancelled');
    }
    private function allocate(SalesOrder $o,array $d): void
    {
        $groups=$o->allocations()->where('status','reserved')->whereNull('pick_task_id')->get()->groupBy('warehouse_id');
        if($groups->isEmpty())$this->fail('Reserve inventory first.');
        foreach($groups as $warehouse=>$rows){
            if(!empty($d['assigned_to'])){
                $user=\App\Models\User::query()->where('company_id',$o->company_id)->findOrFail($d['assigned_to']);
                if(!app(PermissionService::class)->roleHasPermission($user->role,'fulfillment.pick'))$this->fail('The assigned user must have picking permission.');
            }
            $t=PickTask::create(['company_id'=>$o->company_id,'sales_order_id'=>$o->id,'warehouse_id'=>$warehouse,'reference'=>'PICK-'.$o->order_number.'-'.($o->tasks()->count()+1),'assigned_to'=>$d['assigned_to']??null,'priority'=>$o->priority,'status'=>empty($d['assigned_to'])?'pending':'assigned']);
            foreach($rows as $a)$a->update(['pick_task_id'=>$t->id,'status'=>'allocated']);
            $this->event($o,'pick_task.created',['pick_task_id'=>$t->id],(string)$t->id);
        }
        $this->event($o,'fulfillment.allocated',[],(string)$o->allocations()->count());
    }

    private function pick(SalesOrder $o,array $d): void
    {
        $a=$o->allocations()->with('item.product')->findOrFail($d['allocation_id']);
        if(!$a->pick_task_id||$a->status==='released')$this->fail('This item has no active pick task.');
        $t=PickTask::query()->lockForUpdate()->findOrFail($a->pick_task_id);
        if($t->assigned_to && $t->assigned_to!==Auth::id() && !app(PermissionService::class)->roleHasPermission(Auth::user()->role,'fulfillment.manage'))$this->fail('This task is assigned to another picker.');
        if((int)($d['location_id']??0)!==(int)$a->location_id)$this->fail('Scanned location does not match the allocation.');
        if((int)($d['inventory_lot_id']??0)!==(int)$a->inventory_lot_id)$this->fail('Scanned lot does not match the allocation.');
        $product=$a->item->product;
        $barcode=trim($d['barcode']??'');
        if($barcode){$valid=\App\Models\ProductBarcode::query()->where('product_id',$product->id)->where('is_active',true)->where('barcode_normalized',BarcodeIdentity::normalize($barcode))->exists()||BarcodeIdentity::normalize($product->barcode??'')===BarcodeIdentity::normalize($barcode);if(!$valid)$this->fail('Barcode does not match the product on this pick task.');}
        elseif(empty($d['manual_verification']))$this->fail('Scan the product or explicitly verify its identity.');
        if($a->lot?->expiry_at?->lt(now()->startOfDay()))$this->fail('This allocated lot has expired. Release and reallocate it.');
        $qty=self::q($d['quantity']);app(UnitConversionService::class)->assertPrecision((float)$qty,$product->unit);
        if(Money::compareDecimal($qty,$a->picked_quantity)<0||Money::compareDecimal($qty,$a->quantity)>0)$this->fail('Picked quantity must be between the already verified quantity and allocated quantity.');
        if(!$t->started_at){$t->update(['started_at'=>now(),'status'=>'in_progress']);$this->event($o,'pick_task.started',['pick_task_id'=>$t->id],(string)$t->id);}
        $a->update(['picked_quantity'=>$qty]);
        if(!empty($d['reason'])){$a->update(['exception_reason'=>$d['reason']]);$t->update(['status'=>'exception']);$this->event($o,'pick_task.short',['allocation_id'=>$a->id,'reason'=>$d['reason'],'picked'=>$qty],$a->id.':'.sha1($d['reason']));$this->exception($o,'short_pick',$d['reason'],['allocation_id'=>$a->id,'allocated'=>$a->quantity,'picked'=>$qty]);}
        if(!$t->allocations()->where('status','!=','released')->whereColumn('picked_quantity','<','quantity')->exists()){$t->update(['status'=>'completed','completed_at'=>now()]);$this->event($o,'pick_task.completed',['pick_task_id'=>$t->id],(string)$t->id);}
    }

    private function assign(SalesOrder $o,array $d): void
    {
        $task=$o->tasks()->findOrFail($d['task_id']);
        if(in_array($task->status,['completed','cancelled']))$this->fail('Completed or cancelled tasks cannot be reassigned.');
        if(!empty($d['assigned_to'])){
            $user=\App\Models\User::query()->where('company_id',$o->company_id)->findOrFail($d['assigned_to']);
            if(!app(PermissionService::class)->roleHasPermission($user->role,'fulfillment.pick'))$this->fail('The assigned user must have picking permission.');
        }
        $before=$task->assigned_to;
        $task->update(['assigned_to'=>$d['assigned_to']??null,'status'=>$task->started_at?$task->status:(empty($d['assigned_to'])?'pending':'assigned')]);
        $this->event($o,'pick_task.assigned',['pick_task_id'=>$task->id,'previous_user_id'=>$before,'assigned_to'=>$task->assigned_to],$d['idempotency_key']);
    }

    private function pack(SalesOrder $o,array $d): void
    {
        if(empty($d['items']))$this->fail('Select picked items to pack.');
        $p=OutboundPackage::create(['company_id'=>$o->company_id,'sales_order_id'=>$o->id,'reference'=>'PKG-'.$o->id.'-'.($o->packages()->count()+1),'type'=>$d['type']??'box','weight'=>$d['weight']??null,'dimensions'=>$d['dimensions']??null,'notes'=>$d['notes']??null,'packed_by'=>Auth::id(),'packed_at'=>now()]);
        foreach($d['items'] as $row){$a=$o->allocations()->findOrFail($row['allocation_id']);$qty=self::q($row['quantity']);
            app(UnitConversionService::class)->assertPrecision((float)$qty,$a->item->product->unit);
            if(!$this->positive($qty)||Money::compareDecimal($this->add($a->packed_quantity,$qty),$a->picked_quantity)>0)$this->fail('Cannot pack more than the verified picked quantity.');
            $p->items()->create(['company_id'=>$o->company_id,'outbound_allocation_id'=>$a->id,'quantity'=>$qty]);$a->update(['packed_quantity'=>$this->add($a->packed_quantity,$qty)]);}
        $this->event($o,'packing.completed',['package_id'=>$p->id],(string)$p->id);
    }
    private function unpack(SalesOrder $o,array $d): void
    {
        $p=$o->packages()->findOrFail($d['package_id']);if($p->outbound_dispatch_id)$this->fail('Dispatched packages cannot be changed.');
        foreach($p->items as $i){$a=$i->allocation;$a->update(['packed_quantity'=>$this->sub($a->packed_quantity,$i->quantity)]);}$p->items()->delete();$p->delete();
    }

    private function dispatch(SalesOrder $o,array $d): void
    {
        app(OutboundCreditService::class)->authorize($o);
        $packages=$o->packages()->whereIn('id',$d['package_ids']??[])->whereNull('outbound_dispatch_id')->with('items.allocation.item.product')->get();
        if($packages->isEmpty()||$packages->count()!==count(array_unique($d['package_ids']??[])))$this->fail('Select undispatched packages belonging to this order.');
        $dispatch=OutboundDispatch::create(['company_id'=>$o->company_id,'sales_order_id'=>$o->id,'reference'=>'DSP-'.$o->id.'-'.($o->dispatches()->count()+1),'dispatched_at'=>now(),'driver'=>$d['driver']??null,'vehicle'=>$d['vehicle']??null,'notes'=>$d['notes']??null,'created_by'=>Auth::id()]);
        $lines=[]; $values=[]; $packageItems=[];
        foreach($packages as $p){foreach($p->items as $pi){$a=$pi->allocation;$item=$a->item;
            $this->transition($a,'reserved','available',$pi->quantity,'dispatch-release-'.$pi->id);
            $basePrice=Money::divide($item->unit_price,$item->conversion_factor,6);
            $before=$o->allocations()->where('sales_order_item_id',$item->id)->sum('dispatched_quantity');
            $after=$this->add($before,$pi->quantity);
            $values[]=Money::subtract(Money::multiply($item->line_total,Money::divide($after,$item->base_quantity,9)),Money::multiply($item->line_total,Money::divide($before,$item->base_quantity,9)));
            $packageItems[]=$pi;
            $lines[]=['product_id'=>$item->product_id,'quantity'=>$pi->quantity,'unit'=>$item->product->unit,'unit_price'=>$basePrice,'warehouse_id'=>$a->warehouse_id,'location_id'=>$a->location_id,'trace_allocations'=>$this->trace($a,$pi->quantity)];
            $a->update(['dispatched_quantity'=>$this->add($a->dispatched_quantity,$pi->quantity)]);
        }$p->update(['outbound_dispatch_id'=>$dispatch->id]);}
        $sale=app(DailySaleService::class)->create(['sale_date'=>now()->toDateString(),'notes'=>'Fulfillment '.$o->order_number.' / '.$dispatch->reference,'items'=>$lines,'idempotency_key'=>'outbound-sale-'.$dispatch->id],$values);
        foreach($sale->items()->orderBy('id')->get() as $index=>$saleItem)$packageItems[$index]->update(['daily_sale_item_id'=>$saleItem->id]);
        $sale->update(['customer_id'=>$o->customer_id,'customer_name'=>$o->customer?->name??data_get($o->metadata,'guest_customer.name'),'status'=>'finalized','paid_amount'=>$o->payment_type==='cash'?$sale->total_amount:'0.00','payment_method'=>$o->payment_type==='cash'?'cash':null]);
        $dispatch->update(['daily_sale_id'=>$sale->id,'total_amount'=>$sale->total_amount]);
        if($o->payment_type!=='cash'&&Money::compare($sale->total_amount,'0')>0){
            app(OperationalAccountingService::class)->reverseDailySale($sale,'Revenue recognized through customer ledger for '.$dispatch->reference);
            app(CustomerDebtService::class)->addFulfillmentDebt($o,$dispatch);
        }
        $this->event($o,'dispatch.departed',['dispatch_id'=>$dispatch->id,'daily_sale_id'=>$sale->id,'amount'=>$sale->total_amount],(string)$dispatch->id);
    }

    private function delivery(SalesOrder $o,array $d): void
    {
        $dispatch=$o->dispatches()->findOrFail($d['dispatch_id']);
        if($dispatch->status==='delivered')$this->fail('This dispatch has already been delivered. Use the return workflow for corrections.');
        if(!empty($d['failure_reason'])){$dispatch->update(['status'=>'failed','failure_reason'=>$d['failure_reason']]);$this->exception($o,'delivery_failed',$d['failure_reason'],['dispatch_id'=>$dispatch->id]);$this->event($o,'delivery.failed',['dispatch_id'=>$dispatch->id],(string)$dispatch->id);return;}
        if(empty($d['recipient']))$this->fail('Enter the recipient name.');
        $rows=$dispatch->packages()->with('items.allocation')->get()->flatMap->items;
        if(isset($d['items'])&&collect($d['items'])->pluck('package_item_id')->diff($rows->pluck('id'))->isNotEmpty())$this->fail('Delivery items must belong to the selected dispatch.');
        foreach($rows as $pi){$qty=isset($d['items'])?self::q(collect($d['items'])->firstWhere('package_item_id',$pi->id)['quantity']??$pi->delivered_quantity):$pi->quantity;
            app(UnitConversionService::class)->assertPrecision((float)$qty,$pi->allocation->item->product->unit);
            if(Money::compareDecimal($qty,$pi->delivered_quantity)<0||Money::compareDecimal($qty,$pi->quantity)>0)$this->fail('Delivery cannot exceed dispatched quantity or erase a previous delivery.');
            $delta=$this->sub($qty,$pi->delivered_quantity);$a=$pi->allocation;$a->update(['delivered_quantity'=>$this->add($a->delivered_quantity,$delta)]);$pi->update(['delivered_quantity'=>$qty]);}
        $complete=$rows->every(fn($i)=>Money::compareDecimal($i->quantity,$i->delivered_quantity)===0);
        $dispatch->update(['status'=>$complete?'delivered':'partially_delivered','recipient'=>$d['recipient'],'proof_reference'=>$d['proof_reference']??null,'notes'=>$d['notes']??$dispatch->notes,'delivered_at'=>$complete?now():null,'failure_reason'=>null]);
        $this->event($o,'delivery.completed',['dispatch_id'=>$dispatch->id,'partial'=>!$complete],$dispatch->id.':'.sha1($rows->pluck('delivered_quantity')->toJson()));
    }

    private function returnAction(SalesOrder $o,array $d): void
    {
        $stage=$d['stage']??'requested';
        if($stage==='requested'){
            $a=$o->allocations()->findOrFail($d['allocation_id']);$qty=self::q($d['quantity']);
            app(UnitConversionService::class)->assertPrecision((float)$qty,$a->item->product->unit);
            $pending=OutboundReturn::query()->where('outbound_allocation_id',$a->id)->where('status','!=','cancelled')->sum('quantity');
            if(!$this->positive($qty)||Money::compareDecimal($this->add($pending,$qty),$a->delivered_quantity)>0)$this->fail('Return quantity exceeds delivered quantity remaining to return.');
            if(empty($d['reason']))$this->fail('Enter a return reason.');
            $source=$this->returnSource($a,$qty,$d['package_item_id']??null);
            $r=OutboundReturn::create(['company_id'=>$o->company_id,'sales_order_id'=>$o->id,'outbound_allocation_id'=>$a->id,'outbound_package_item_id'=>$source->id,'reference'=>'RMA-'.$o->id.'-'.($o->returns()->count()+1),'quantity'=>$qty,'reason'=>$d['reason'],'created_by'=>Auth::id()]);$this->event($o,'customer_return.created',['return_id'=>$r->id],(string)$r->id);return;
        }
        $r=$o->returns()->findOrFail($d['return_id']);
        if($stage==='cancelled'){if(in_array($r->status,['received','resolved']))$this->fail('A physically received return cannot be cancelled.');$r->update(['status'=>'cancelled']);return;}
        if($stage==='authorized'&&$r->status==='requested'){$r->update(['status'=>'authorized']);return;}
        if($stage==='received'&&$r->status==='authorized'){$r->update(['status'=>'received','received_at'=>now()]);$this->event($o,'customer_return.received',['return_id'=>$r->id],(string)$r->id);return;}
        if($stage!=='resolved'||$r->status!=='received')$this->fail('Authorize and receive the return before resolving it.');
        if(!$o->customer_id){
            abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,'financial_accounts.adjust'),403,'Guest cash refunds require money-account posting permission.');
            if(empty($d['financial_account_id']))$this->fail('Choose the cash/bank account used to refund this guest.');
        }
        $a=$r->allocation;$item=$a->item;$condition=$d['disposition']??'quarantine';if(!in_array($condition,['sellable','quarantine','damaged','scrap']))$this->fail('Choose a valid return disposition.');
        $service=app(InventoryReturnService::class);
        $source=$this->returnSource($a,$r->quantity,$r->outbound_package_item_id,$r->id);
        $r->update(['outbound_package_item_id'=>$source->id]);
        $saleItem=\App\Models\DailySaleItem::query()->findOrFail($source->daily_sale_item_id);
        $return=$service->create(['type'=>'customer','customer_id'=>$o->customer_id,'daily_sale_id'=>$saleItem->daily_sale_id,'reason'=>$r->reason,'financial_resolution'=>$o->customer_id?'debt_credit':'cash_refund','financial_account_id'=>$o->customer_id?null:($d['financial_account_id']??null),'financial_amount'=>Money::multiply(Money::divide($item->unit_price,$item->conversion_factor,6),$r->quantity),'idempotency_key'=>'outbound-rma-'.$r->id,'items'=>[['product_id'=>$item->product_id,'daily_sale_item_id'=>$saleItem->id,'warehouse_id'=>$a->warehouse_id,'location_id'=>$a->location_id,'quantity'=>$r->quantity,'condition'=>$condition,'trace_data'=>['allocations'=>$this->trace($a,$r->quantity)]]]]);
        $service->submit($return);$service->approve($return);$service->complete($return);
        $a->update(['returned_quantity'=>$this->add($a->returned_quantity,$r->quantity)]);$r->update(['status'=>'resolved','disposition'=>$condition,'inventory_return_id'=>$return->id,'resolved_at'=>now()]);
        $this->event($o,'customer_return.resolved',['return_id'=>$r->id,'inventory_return_id'=>$return->id],(string)$r->id);
    }

    private function returnSource(OutboundAllocation $allocation,string $quantity,?int $sourceId=null,?int $excludeReturn=null): OutboundPackageItem
    {
        $sources=OutboundPackageItem::query()->where('outbound_allocation_id',$allocation->id)->whereNotNull('daily_sale_item_id')->where('delivered_quantity','>',0)->when($sourceId,fn($q)=>$q->whereKey($sourceId))->orderBy('id')->get();
        if(!$sourceId&&$sources->count()>1)$this->fail('Select the original delivered package. Returns from separate deliveries must be recorded separately.');
        foreach($sources as $source){
            $used=OutboundReturn::query()->where('status','!=','cancelled')->where('outbound_package_item_id',$source->id)->when($excludeReturn,fn($q)=>$q->whereKeyNot($excludeReturn))->sum('quantity');
            // Historical generic returns also consume this original sale line.
            $legacy=\App\Models\InventoryReturnItem::query()->where('daily_sale_item_id',$source->daily_sale_item_id)->whereHas('inventoryReturn',fn($q)=>$q->where('status','!=','cancelled')->whereNotIn('id',OutboundReturn::query()->whereNotNull('outbound_package_item_id')->whereNotNull('inventory_return_id')->select('inventory_return_id')))->sum('quantity');
            if(Money::compareDecimal($this->add($used,$legacy),$source->delivered_quantity)<=0&&Money::compareDecimal($quantity,$this->sub($source->delivered_quantity,$this->add($used,$legacy)))<=0)return $source;
        }
        $this->fail('Return quantity exceeds the remaining delivered quantity of this package.');
    }

    public function sync(SalesOrder $o): void
    {
        $this->resolveExceptions($o);
        foreach($o->tasks()->whereNotNull('pick_wave_id')->pluck('pick_wave_id')->unique() as $waveId){
            $wave=PickWave::find($waveId);if(!$wave)continue;
            $tasks=$wave->tasks()->get();$active=$tasks->whereNotIn('status',['completed','cancelled']);
            $wave->update(['status'=>$active->isEmpty()?($tasks->every(fn($t)=>$t->status==='cancelled')?'cancelled':'completed'):($tasks->contains(fn($t)=>(bool)$t->started_at)?'in_progress':'open')]);
        }
        if($o->status==='cancelled')return;
        foreach($o->items as $i){$rows=$i->allocations()->where('status','!=','released')->get();$values=[];
            foreach(['picked','packed','dispatched','delivered','returned'] as $s)$values[$s.'_quantity']=$this->sum($rows,$s.'_quantity');
            $values['reserved_quantity']=$this->sub($this->sum($rows,'quantity'),$values['dispatched_quantity']);
            $values['allocated_quantity']=$this->sum($rows->whereNotNull('pick_task_id'),'quantity');$i->update($values);}
        if(!$o->confirmed_at)return;
        $items=$o->items()->get();$total=$this->sum($items,'base_quantity');$status='ready_to_allocate';
        foreach(['allocated','picked','packed','dispatched','delivered'] as $s){$qty=$this->sum($items,$s.'_quantity');if($this->positive($qty))$status=Money::compareDecimal($qty,$total)>=0?$s:'partially_'.$s;}
        $o->update(['status'=>$status,'completed_at'=>$status==='delivered'?($o->completed_at??now()):null]);
    }

    private function resolveExceptions(SalesOrder $o): void
    {
        foreach(OperationalException::query()->where('entity_type','SalesOrder')->where('entity_id',$o->id)->where('status','open')->get() as $exception){
            $v=$exception->relevant_values??[];$resolved=$o->status==='cancelled';
            if($exception->exception_type==='allocation_incomplete'){
                $item=$o->items()->find($v['item_id']??0);
                $reserved=$item?$item->allocations()->where('status','!=','released')->sum('quantity'):'0';
                $resolved=$resolved||($item&&Money::compareDecimal($reserved,$item->base_quantity)>=0);
            }elseif($exception->exception_type==='short_pick'){
                $a=$o->allocations()->find($v['allocation_id']??0);
                $resolved=$resolved||($a&&($a->status==='released'||Money::compareDecimal($a->picked_quantity,$a->quantity)>=0));
            }elseif($exception->exception_type==='delivery_failed'){
                $resolved=$resolved||$o->dispatches()->whereKey($v['dispatch_id']??0)->where('status','!=','failed')->exists();
            }
            if($resolved)$exception->update(['status'=>'resolved','resolved_at'=>now(),'resolved_by'=>Auth::id()]);
        }
    }

    public function wave(array $d): PickWave
    {
        return DB::transaction(function()use($d){Warehouse::query()->findOrFail($d['warehouse_id']);$tasks=PickTask::query()->whereIn('id',$d['task_ids'])->where('warehouse_id',$d['warehouse_id'])->whereIn('status',['pending','assigned'])->whereNull('pick_wave_id')->lockForUpdate()->get();if($tasks->count()!==count(array_unique($d['task_ids'])))$this->fail('Select unstarted tasks in the same warehouse.');
            $wave=PickWave::create(['company_id'=>Auth::user()->company_id,'warehouse_id'=>$d['warehouse_id'],'reference'=>'WAVE-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),'created_by'=>Auth::id()]);foreach($tasks as $t)$t->update(['pick_wave_id'=>$wave->id]);return $wave->load('tasks');});
    }

    private function event(SalesOrder $o,string $type,array $data=[],string $suffix=''): void {
        $event=app(BusinessEventService::class)->record($type,$o,$o->order_number,$data,$type.':'.$o->id.':'.$suffix);
        if($event?->wasRecentlyCreated && (in_array($type,['delivery.failed','customer_return.received','pick_task.short'])||($type==='pick_task.created'&&$o->priority===1)))
            \App\Models\Notification::create(['company_id'=>$o->company_id,'type'=>'fulfillment','title'=>$o->order_number,'message'=>str_replace(['.','_'],' ',$type),'data'=>['sales_order_id'=>$o->id,'event_id'=>$event->id]]);
    }
    private function exception(SalesOrder $o,string $type,string $description,array $values): void
    {
        OperationalException::query()->updateOrCreate(['company_id'=>$o->company_id,'exception_key'=>'outbound:'.$o->id.':'.$type.':'.($values['allocation_id']??$values['item_id']??$values['dispatch_id']??0)],['exception_type'=>$type,'entity_type'=>'SalesOrder','entity_id'=>$o->id,'severity'=>'warning','status'=>'open','detected_at'=>now(),'description'=>$description,'relevant_values'=>$values,'next_action'=>['url'=>'/fulfillment?order='.$o->id,'label'=>'Open sales order']]);
    }
}
