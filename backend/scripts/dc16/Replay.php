<?php
/** Test-only deterministic agenda; all quantities and money go through AIMS authorities. */
class Dc16Replay
{
    public array $state=['receipts'=>[],'collections'=>[],'milestones'=>[],'notes'=>[]];
    public int $day=0;
    public function key(string $purpose): string
    {
        $key='dc16-20261102-'.$this->day.'-'.$purpose;
        // Retain completed historical request identities; later keys fit UUID
        // columns even when purpose names or database identifiers grow.
        if($this->day<102)return $key;
        $h=hash('sha256',$key);
        return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12);
    }
    public function sample(string $purpose,int $min,int $max): int { return $min+hexdec(substr(hash('sha256',$this->key($purpose)),0,7))%($max-$min+1); }
    public function owner(): void {global $dc16Owner; Illuminate\Support\Facades\Auth::setUser($dc16Owner);}
    public function run(int $day): void
    {
        $this->day=$day; dc16Clock(Carbon\CarbonImmutable::parse('2025-11-01')->addDays($day)->format('Y-m-d').' 08:00:00'); $this->owner();
        $this->state['phase_metrics']=[];
        // Forecast before today's transactions exist, using yesterday's cutoff.
        if(in_array($day,[241,272],true))$this->freezeForecasts();
        $this->timed('incoming',fn()=>$this->incoming());
        foreach($this->state['release_orders'][$day]??[] as $id)app(App\Services\OutboundService::class)->action(App\Models\SalesOrder::findOrFail($id),'cancel',['idempotency_key'=>$this->key('backorder-release-'.$id),'reason'=>'Synthetic customer releases the delayed backorder after review; no stock disposal or sale.']);
        unset($this->state['release_orders'][$day]);
        if($day%7===1)$this->timed('purchasing',fn()=>$this->purchase());
        if(today()->dayOfWeekIso!==7){ $this->timed('sales_slot_0',fn()=>$this->sales(0)); $this->timed('sales_slot_1',fn()=>$this->sales(1)); }
        $this->timed('collections',fn()=>$this->collections());
        if($day>=96 && today()->day===8)$this->workforce();
        if($day>=96 && today()->day===10)$this->transfers();
        if($day>=165 && today()->day===15)$this->backorder();
        if(today()->day===12)$this->expenses();
        if(today()->day===18)$this->count();
        if(today()->isLastOfMonth())$this->timed('payroll',fn()=>$this->payroll());
        dc16Clock(today()->toDateString().' 23:40:00');$this->owner();
        $daily=app(App\Services\DailySaleService::class);
        $daily->updateDayNotes(today()->toDateString(),'DC16 SYNTHETIC / '.(today()->dayOfWeekIso===7?'closed Sunday':'wholesale operations'));
        if(App\Models\DailySale::whereDate('sale_date',today())->exists())$daily->finalizeDay(today()->toDateString());
        // Preserve the original prospective capture boundary on exact reruns.
        if($day>=34)$this->timed('analytics_capture',fn()=>app(App\Services\AnalyticsDataService::class)->capture());
        $this->timed('automation',fn()=>app(App\Services\AutomationScheduler::class)->tick());
        // Collect only completed historical days. No future outcome is used.
        // Thirty fixed products cover meter/piece/kg families. Other products
        // retain all authoritative transactions but are not claimed model cohorts.
        $cohort=array_map(fn($i)=>sprintf('DC16-P%03d',1+$i*10),range(0,29));
        if($day>0)foreach(App\Models\Product::when($day>=3,fn($q)=>$q->whereIn('sku',$cohort))->orderBy('id')->get() as $p)app(App\Services\IntelligenceObservationService::class)->collectProduct($p,today()->subDay()->toDateString(),today()->subDay()->toDateString());
    }
    private function timed(string $phase,callable $operation): void
    {
        $trace=app(App\Services\Platform\PlatformTrace::class);$before=$trace->stats();$start=microtime(true);
        $operation();$after=$trace->stats();
        $this->state['phase_metrics'][$phase]=['seconds'=>round(microtime(true)-$start,3),'queries'=>$after['query_count']-$before['query_count'],'db_ms'=>round($after['db_duration_ms']-$before['db_duration_ms'],3)];
    }
    private function purchase(): void
    {
        $products=App\Models\Product::orderBy('id')->get();
        $offset=(intdiv($this->day,7)*13)%300;
        $batch=$products->slice($offset,13)->concat($products->take(max(0,$offset+13-300)))->values();
        $suppliers=App\Models\Supplier::orderBy('id')->get();$supplier=$suppliers[intdiv($this->day,7)%$suppliers->count()];
        $warehouse=App\Models\Warehouse::orderBy('id')->get()[intdiv($this->day,7)%4];
        $items=$batch->map(fn($p)=>['product_id'=>$p->id,'description'=>$p->name,'unit'=>$p->unit,'quantity'=>$p->unit==='m'?200:($p->unit==='pcs'?80:30),'unit_price'=>$p->purchase_price])->all();
        $poService=app(App\Services\PurchaseOrderService::class);
        if(today()->day<=7){
            $pr=app(App\Services\ProcurementService::class);
            $request=$pr->createRequest(['requested_at'=>today()->toDateString(),'required_by'=>today()->addDays(12)->toDateString(),'currency'=>'EUR','notes'=>'DC16 qualified synthetic replenishment','items'=>array_map(fn($i)=>['product_id'=>$i['product_id'],'description'=>$i['description'],'unit'=>$i['unit'],'quantity'=>$i['quantity'],'estimated_unit_price'=>$i['unit_price']],$items)]);
            $request=$pr->submit($request);
            $vendors=[$supplier,$suppliers[(intdiv($this->day,7)+1)%$suppliers->count()]];
            $rfq=$pr->issueRfq($pr->createRfq($request,['supplier_ids'=>array_map(fn($s)=>$s->id,$vendors),'response_due_at'=>today()->addDays(2)->toDateString()]));
            $quotes=[];
            foreach($vendors as $n=>$vendor)$quotes[]=$pr->createQuote($rfq,['supplier_id'=>$vendor->id,'currency'=>'EUR','exchange_rate'=>1,'quoted_at'=>now(),'valid_until'=>today()->addDays(14)->toDateString(),'items'=>$request->items->map(fn($i)=>['purchase_request_item_id'=>$i->id,'offered_quantity'=>$i->quantity,'unit_price'=>round((float)$i->estimated_unit_price*(1+$n*.07),2),'lead_time_days'=>7+$n*2,'minimum_order_quantity'=>1,'order_multiple'=>$i->unit==='m'?.001:1])->all()]);
            $rfq=$pr->award($rfq,$quotes[0]->items->pluck('id')->all());
            $ids=$pr->convertAwards($rfq,$this->key('award'),$warehouse->id);
            $po=App\Models\PurchaseOrder::findOrFail($ids[0]);
            $poService->changeStatus($po,'ordered','Synthetic authorized award');$po=$po->fresh();
        }else $po=$poService->create(['supplier_id'=>$supplier->id,'warehouse_id'=>$warehouse->id,'status'=>'ordered','currency'=>'EUR','exchange_rate'=>1,'ordered_at'=>today()->toDateString(),'expected_at'=>today()->addDays(7)->toDateString(),'due_at'=>today()->addDays(28)->toDateString(),'notes'=>'DC16 synthetic purchase','items'=>$items]);
        if($this->day%49===15){$poService->cancel($po,'Synthetic supplier cannot confirm before payment or receipt');return;}
        $bank=App\Models\FinancialAccount::where('type','bank')->value('id');
        $poService->pay($po,['amount'=>App\Support\Money::multiply($po->total_amount,'.24'),'payment_date'=>today()->toDateString(),'payment_method'=>'bank_transfer','financial_account_id'=>$bank,'idempotency_key'=>$this->key('po-advance'),'note'=>'DC16 advance before receipt']);
        // Synthetic shipment fixture metadata only: never masquerades as live AIS.
        $shipment=App\Models\Shipment::create(['tracking_number'=>'DC16-CARGO-'.$po->id,'purchase_order_id'=>$po->id,'supplier_id'=>$po->supplier_id,'warehouse_id'=>$warehouse->id,'transport_mode'=>'sea','carrier'=>'SYNTHETIC — no provider connection','origin_port'=>'Istanbul','destination_port'=>'Durres','status'=>'planned','eta'=>today()->addDays(7),'tracking_provider'=>'synthetic','tracking_mode'=>'synthetic','is_saved'=>true,'notes'=>'Test-only simulated logistics; no verified AIS evidence.']);
        $shipment->purchaseOrders()->syncWithPivotValues([$po->id],['company_id'=>$po->company_id]);
        $lead=7+($this->day%21===1?4:0);
        foreach(['supplier_dispatch'=>1,'warehouse_arrival'=>$lead] as $type=>$offset){
            app(App\Services\ControlTowerService::class)->upsertMilestone($shipment,['milestone_type'=>$type,'status'=>'planned','planned_at'=>today()->addDays($type==='warehouse_arrival'?7:1)->toIso8601String(),'actual_at'=>null,'notes'=>'DC16 synthetic planned event']);
            $this->state['milestones'][$this->day+$offset][]=[$shipment->id,$type];
        }
        $this->state['receipts'][$this->day+$lead][]=[$po->id,.6];$this->state['receipts'][$this->day+$lead+3][]=[$po->id,1];
    }
    private function incoming(): void
    {
        foreach($this->state['milestones'][$this->day]??[] as [$id,$type]){
            $s=App\Models\Shipment::findOrFail($id);$planned=$s->milestones()->where('milestone_type',$type)->first()->planned_at->toIso8601String();
            app(App\Services\ControlTowerService::class)->upsertMilestone($s,['milestone_type'=>$type,'status'=>'completed','planned_at'=>$planned,'actual_at'=>now()->toIso8601String(),'notes'=>'DC16 synthetic event recorded as it occurs']);
            $s->update($type==='warehouse_arrival'?['status'=>'delivered','arrival_date'=>now()]:['status'=>'in_transit','departed_at'=>now()]);
        }
        foreach($this->state['receipts'][$this->day]??[] as [$id,$fraction]){
            $po=App\Models\PurchaseOrder::with('items')->findOrFail($id);
            $items=$po->items->map(fn($i)=>['id'=>$i->id,'quantity'=>$fraction<1?floor(((float)$i->quantity-(float)$i->received_quantity)*$fraction):(float)$i->quantity-(float)$i->received_quantity])->filter(fn($i)=>$i['quantity']>0)->values()->all();
            if(!$items)continue;
            $payload=['items'=>$items,'received_at'=>today()->toDateString(),'idempotency_key'=>$this->key('receipt-'.$id)];
            $service=app(App\Services\PurchaseOrderService::class);$service->receive($po,$payload);
            $receipt=App\Models\GoodsReceipt::where('purchase_order_id',$id)->latest('id')->firstOrFail();
            // Exercise receipt retry against the same authority and payload.
            $before=App\Models\StockMovement::count();$service->receive($po->fresh(),$payload);
            if($before!==App\Models\StockMovement::count())throw new RuntimeException('Receipt retry duplicated a stock movement');
            $qualityDue=$this->day<96?$receipt->id%6===0:$this->sample('quality-'.$po->ordered_at->toDateString().'-'.$fraction,0,5)===0;
            if($qualityDue){$q=app(App\Services\QualityManagementService::class);$inspection=$q->createForReceiptItem($receipt->items()->firstOrFail(),['notes'=>'DC16 incoming quality review']);$q->finalize($inspection,['accepted_quantity'=>(float)$inspection->received_quantity-1,'rejected_quantity'=>0,'quarantine_quantity'=>0,'damaged_quantity'=>1,'notes'=>'One synthetic damaged unit found']);}
            if($fraction===1){
                foreach(['freight'=>70,'customs'=>25,'insurance'=>8,'other'=>30] as $kind=>$amount){$lc=app(App\Services\LandedCostService::class);$lc->post($lc->createDraft(['goods_receipt_id'=>$receipt->id,'cost_type'=>$kind,'amount'=>$amount,'currency'=>'EUR','allocation_method'=>'value','idempotency_key'=>$this->key('landed-'.$id.'-'.$kind),'notes'=>'DC16 synthetic '.$kind]));}
                if($this->day>=96){
                    $matching=app(App\Services\SupplierInvoiceMatchingService::class);
                    $po=$po->fresh('items.product');
                    $bill=$matching->create(['supplier_id'=>$po->supplier_id,'purchase_order_id'=>$po->id,'vendor_name'=>$po->supplier->name,'vendor_business_number'=>'TEST-DC16-SUPPLIER-'.$po->supplier_id,'document_type'=>'purchase_invoice','document_number'=>'DC16-BILL-'.$po->id,'source_type'=>'domestic','asset_treatment'=>'ordinary','category'=>'inventory','business_purpose'=>'Synthetic wholesale purchases','invoice_date'=>today()->toDateString(),'supply_date'=>today()->toDateString(),'received_date'=>today()->toDateString(),'due_date'=>today()->addDays(21)->toDateString(),'currency'=>'EUR','exchange_rate'=>1,'net_amount'=>$po->total_amount,'vat_rate'=>0,'vat_amount'=>0,'vat_treatment'=>'non_vat','input_vat_eligible'=>false,'deductible_vat_amount'=>0,'goods_receipt_ids'=>App\Models\GoodsReceipt::where('purchase_order_id',$po->id)->pluck('id')->all(),'items'=>$po->items->map(fn($i)=>['purchase_order_item_id'=>$i->id,'product_id'=>$i->product_id,'description'=>$i->product->name,'quantity'=>$i->quantity,'unit'=>$i->product->unit,'unit_price'=>$i->unit_price,'vat_rate'=>0])->all()]);
                    if($this->sample('supplier-review',0,9)!==0)$matching->approve($bill,'Synthetic accountant reviewed PO, both receipts and supplier statement.');
                }
                $po=$po->fresh();$amount=App\Support\Money::multiply($po->remaining_balance,'.5');
                if(App\Support\Money::compare($amount,0)>0)$service->pay($po,['amount'=>$amount,'payment_date'=>today()->toDateString(),'payment_method'=>'bank_transfer','financial_account_id'=>App\Models\FinancialAccount::where('type','bank')->value('id'),'idempotency_key'=>$this->key('po-settlement-'.$id),'note'=>'DC16 partial supplier settlement']);
            }
        }
        unset($this->state['receipts'][$this->day],$this->state['milestones'][$this->day]);
    }
    private function sales(int $slot): void
    {
        $warehouse=App\Models\Warehouse::orderBy('id')->get()[($this->day+$slot)%4];
        $stocks=App\Models\WarehouseStock::where('warehouse_id',$warehouse->id)->where('available_quantity','>',2)->with('product')->orderBy('product_id')->get();
        if($stocks->isEmpty())return;
        $items=[];$offset=$this->sample('product-start-'.$slot,0,$stocks->count()-1);
        $limit=$this->day===20&&$slot===0?75:6;
        for($n=0;$n<min($limit,$stocks->count());$n++){
            $stock=$stocks[($offset+$n)%$stocks->count()];$p=$stock->product;
            $season=[1.1,1.25,.8,.75,.9,1,1.15,1.2,.95,.85,1.1,1.35][(int)today()->month-1];
            $q=$p->unit==='m'?round($this->sample('qty-'.$slot.'-'.$n,2,18)*$season+.375,3):$this->sample('qty-'.$slot.'-'.$n,1,5);
            $q=min($q,(float)$stock->available_quantity);
            $items[]=['product_id'=>$p->id,'quantity'=>$q,'unit'=>$p->unit,'unit_price'=>$p->selling_price];
        }
        $customers=App\Models\Customer::orderBy('id')->get();$customer=$customers[$this->sample('customer-'.$slot,0,$this->day>270?99:119)];
        $out=app(App\Services\OutboundService::class);
        if($this->day>=65 && $slot===0 && $this->day%14===7){
            $channel=App\Models\OrderChannel::where('name','DC16 Wholesale '.$warehouse->id)->first();
            if(!$channel){
                $r=Illuminate\Http\Request::create('/','POST',['name'=>'DC16 Wholesale '.$warehouse->id,'type'=>'manual','enabled'=>true,'warehouse_id'=>$warehouse->id,'currency'=>'EUR','allow_guest'=>false,'acceptance'=>'review','oversale_policy'=>'accept_backorder','price_tolerance'=>0,'configuration'=>['price_rules'=>[]]]);
                $r->setUserResolver(fn()=>auth()->user());
                $response=app(App\Http\Controllers\Api\OrderHubController::class)->channel($r);
                $channel=App\Models\OrderChannel::findOrFail($response->getData(true)['id']);
            }
            $quotes=app(App\Services\CustomerQuotationService::class);
            $quote=$quotes->create(['customer_id'=>$customer->id,'order_channel_id'=>$channel->id,'quote_date'=>today()->toDateString(),'expires_on'=>today()->addDays(14)->toDateString(),'payment_type'=>'credit','idempotency_key'=>$this->key('quotation'),'billing_address'=>$customer->billing_address,'delivery_address'=>$customer->billing_address,'items'=>array_map(fn($i)=>array_intersect_key($i,array_flip(['product_id','quantity','unit'])),$items)]);
            $quote=$quotes->transition($quote,'send');$quote=$quotes->transition($quote,'accept');$quote=$quotes->convert($quote);
            $retry=$quotes->convert($quote->fresh());if($retry->converted_order_id!==$quote->converted_order_id)throw new RuntimeException('Quotation retry created another order');
            $order=App\Models\SalesOrder::findOrFail($quote->converted_order_id);
            app(App\Services\CustomerWorkspaceService::class)->note($customer,['kind'=>'call','note'=>'Synthetic buyer accepted the replenishment quotation. No communication was sent.','occurred_at'=>now()->toIso8601String(),'idempotency_key'=>$this->key('crm-note')]);
            app(App\Services\CustomerWorkspaceService::class)->followup($customer,['title'=>'Review synthetic delivery and payment commitment','description'=>'DC16 customer relationship follow-up, not a payment posting','due_at'=>today()->addDays(21)->toDateTimeString(),'priority'=>'normal','idempotency_key'=>$this->key('crm-followup')]);
        }else{
            $order=$out->create(['customer_id'=>$customer->id,'warehouse_id'=>$warehouse->id,'order_date'=>today()->toDateString(),'requested_delivery_date'=>today()->addDays(2)->toDateString(),'currency'=>'EUR','payment_type'=>'credit','idempotency_key'=>$this->key('order-'.$slot),'notes'=>'DC16 synthetic wholesale order','items'=>$items]);
            app(App\Services\OrderHubService::class)->attachManual($order,['customer_id'=>$customer->id,'items'=>$items,'payment_type'=>'credit']);
        }
        if(($this->day+$slot)%37===10){$out->action($order,'cancel',['idempotency_key'=>$this->key('cancel-'.$slot),'reason'=>'Synthetic customer cancellation before fulfillment']);return;}
        foreach(['confirm','reserve','allocate'] as $action)$out->action($order->fresh(),$action,['idempotency_key'=>$this->key($action.'-'.$slot)]);
        $order=$order->fresh('allocations');
        foreach($order->allocations as $a)$out->action($order,'pick',['allocation_id'=>$a->id,'location_id'=>$a->location_id,'inventory_lot_id'=>$a->inventory_lot_id,'quantity'=>$a->quantity,'manual_verification'=>true,'reason'=>'DC16 synthetic manual warehouse verification','idempotency_key'=>$this->key('pick-'.$slot.'-'.$a->id)]);
        $order=$order->fresh('allocations');$out->action($order,'pack',['items'=>$order->allocations->map(fn($a)=>['allocation_id'=>$a->id,'quantity'=>$a->picked_quantity])->values()->all(),'idempotency_key'=>$this->key('pack-'.$slot)]);
        $order=$order->fresh('packages');$payload=['package_ids'=>$order->packages->pluck('id')->all(),'idempotency_key'=>$this->key('dispatch-'.$slot)];
        $out->action($order,'dispatch',$payload);$before=App\Models\StockMovement::count();$out->action($order->fresh(), 'dispatch',$payload);
        if($before!==App\Models\StockMovement::count())throw new RuntimeException('Dispatch retry duplicated stock movement');
        $dispatch=$order->fresh('dispatches')->dispatches->last();
        if($this->day%7===0 || $this->day===20){
            $sale=App\Models\DailySale::with('items')->findOrFail($dispatch->daily_sale_id);
            $invoice=app(App\Services\InvoiceService::class)->createDraft(['customer_id'=>$customer->id,'invoice_date'=>today()->toDateString(),'supply_date'=>today()->toDateString(),'due_date'=>today()->addDays(21)->toDateString(),'notes'=>'DC16 supporting invoice for existing dispatch; no duplicate sale','items'=>$sale->items->map(fn($i)=>['product_id'=>$i->product_id,'description'=>$i->product_name,'unit'=>$i->unit,'quantity'=>$i->quantity,'unit_price'=>$i->unit_price])->all()],$sale);
            app(App\Services\InvoiceService::class)->issue($invoice);
        }
        $out->action($order->fresh(),'delivery',['dispatch_id'=>$dispatch->id,'recipient'=>$customer->name,'idempotency_key'=>$this->key('delivery-'.$slot)]);
        if($this->day>=65 && $slot===0 && $this->day%29===14){
            $order=$order->fresh('allocations');$a=$order->allocations->first();
            $out->action($order,'return',['stage'=>'requested','allocation_id'=>$a->id,'quantity'=>1,'reason'=>'DC16 damaged package after delivery','idempotency_key'=>$this->key('return-request')]);
            $return=$order->fresh('returns')->returns->last();
            foreach(['authorized','received','resolved'] as $stage)$out->action($order->fresh(),'return',['stage'=>$stage,'return_id'=>$return->id,'disposition'=>'quarantine','idempotency_key'=>$this->key('return-'.$stage)]);
        }
        $this->state['collections'][$this->day+7+$customer->id%11][]=[$customer->id,App\Support\Money::multiply($dispatch->total_amount,'.4'),$order->id];
        if($customer->id%7!==0)$this->state['collections'][$this->day+25+$customer->id%10][]=[$customer->id,App\Support\Money::multiply($dispatch->total_amount,'.6'),$order->id];
    }
    private function collections(): void
    {
        $service=app(App\Services\CustomerDebtService::class);$bank=App\Models\FinancialAccount::where('type','bank')->value('id');
        foreach($this->state['collections'][$this->day]??[] as $n=>[$id,$amount,$order])if(App\Support\Money::compare($amount,0)>0)$service->recordPayment(App\Models\Customer::findOrFail($id),['amount'=>$amount,'transaction_date'=>today()->toDateString(),'financial_account_id'=>$bank,'payment_method'=>'bank_transfer','reference_number'=>'DC16-SO-'.$order,'note'=>'DC16 scheduled partial collection','idempotency_key'=>$this->key('collection-'.$n)]);
        unset($this->state['collections'][$this->day]);
        if(today()->day===3)$service->recordPayment(App\Models\Customer::orderBy('id')->skip(10)->first(),['amount'=>'1000.00','transaction_date'=>today()->toDateString(),'financial_account_id'=>$bank,'payment_method'=>'bank_transfer','note'=>'DC16 customer advance before later purchases','idempotency_key'=>$this->key('customer-advance')]);
    }
    private function expenses(): void
    {
        $service=app(App\Services\ExpenseService::class);
        foreach(['rent'=>1800,'utilities'=>420,'transport'=>260] as $category=>$amount){$e=$service->create(['vendor_name'=>'DC16 '.$category.' supplier','document_type'=>'purchase_invoice','document_number'=>$this->key('expense-'.$category),'invoice_date'=>today()->toDateString(),'received_date'=>today()->toDateString(),'supply_date'=>today()->toDateString(),'due_date'=>today()->addDays(7)->toDateString(),'currency'=>'EUR','net_amount'=>$amount,'vat_amount'=>0,'vat_rate'=>0,'vat_treatment'=>'non_vat','input_vat_eligible'=>false,'category'=>'other','source_type'=>'domestic','asset_treatment'=>'ordinary','description'=>'DC16 synthetic '.$category]);$e=$service->post($e);$service->recordPayment($e,['amount'=>$amount,'payment_date'=>today()->toDateString(),'payment_method'=>'bank_transfer','financial_account_id'=>App\Models\FinancialAccount::where('type','bank')->value('id'),'idempotency_key'=>$this->key('expense-paid-'.$category)]);}
    }
    private function count(): void
    {
        $service=app(App\Services\InventoryCountService::class);$w=App\Models\Warehouse::orderBy('id')->first();
        $ids=App\Models\WarehouseStock::where('warehouse_id',$w->id)->where('available_quantity','>',3)->limit(3)->pluck('product_id')->all();if(!$ids)return;
        $c=$service->create(['warehouse_id'=>$w->id,'product_ids'=>$ids,'stock_states'=>['available'],'notes'=>'DC16 cycle count with independently reviewed one-unit shortage']);
        $items=$c->items->map(fn($i,$n)=>['count_item_id'=>$i->id,'counted_quantity'=>max(0,(float)$i->expected_quantity-($n===0?1:0)),'notes'=>'DC16 verified count'])->all();
        $c=$service->record($c,['items'=>$items]);$c=$service->requestRecount($c,[$c->items->first()->id],'Independent synthetic recount');$c=$service->record($c,['items'=>$items]);$c=$service->submit($c);
        global $dc16Accounts;Illuminate\Support\Facades\Auth::setUser(App\Models\User::withoutGlobalScopes()->findOrFail($dc16Accounts['manager']['user_id']));
        try{$service->approve($c,'Separate test manager confirms physical variance');}finally{$this->owner();}
    }
    private function payroll(): void
    {
        $service=app(App\Services\Workforce\PayrollService::class);$date=today()->toDateString();
        $run=$service->createPeriod(['name'=>'DC16 '.today()->format('Y-m'),'starts_on'=>today()->startOfMonth()->toDateString(),'ends_on'=>$date,'pay_date'=>$date]);
        $run=$service->calculate($run->id);$run=$service->approve($run->id,$run->fingerprint,true);$run=$service->post($run->id,$run->fingerprint);
        $bank=App\Models\FinancialAccount::where('type','bank')->value('id');
        foreach(App\Models\PayrollLine::where('payroll_run_id',$run->id)->get() as $line)$service->pay($run->id,['kind'=>'salary','payroll_line_id'=>$line->id,'financial_account_id'=>$bank,'amount'=>$line->net,'paid_on'=>$date,'idempotency_key'=>$this->key('salary-'.$line->id)]);
    }
    private function workforce(): void
    {
        $workforce=app(App\Services\Workforce\WorkforceService::class);
        $employees=App\Models\Employee::orderBy('id')->get();
        $employee=$employees[today()->month%20];$date=today()->toDateString();
        foreach(['attendance'=>['attendance_status'=>'present'],'leave'=>['leave_type'=>'sick']] as $kind=>$details){
            $worker=$kind==='attendance'?$employees[(today()->month+1)%20]:$employee;
            $record=$workforce->record(['employee_id'=>$worker->id,'kind'=>$kind,'starts_on'=>$date,'ends_on'=>$date,'units'=>1,'amount'=>'0.00','reason'=>'DC16 synthetic workforce evidence','details'=>$details]);
            $workforce->decide($record->id,'approved','Reviewed synthetic attendance/leave instruction');
        }
        $payroll=app(App\Services\Workforce\PayrollService::class);$bank=App\Models\FinancialAccount::where('type','bank')->value('id');
        $payload=['financial_account_id'=>$bank,'amount'=>'150.00','paid_on'=>$date,'reason'=>'DC16 approved employee advance','idempotency_key'=>$this->key('employee-advance')];
        $advance=$payroll->advance($employee->id,$payload);$retry=$payroll->advance($employee->id,$payload);
        if($advance->id!==$retry->id)throw new RuntimeException('Employee advance retry duplicated a payment');
        $record=$workforce->record(['employee_id'=>$employee->id,'kind'=>'advance_repayment','starts_on'=>$date,'ends_on'=>$date,'units'=>1,'amount'=>'50.00','reason'=>'DC16 authorized partial advance repayment','details'=>[]]);
        $workforce->decide($record->id,'approved','Reviewed synthetic payroll repayment');
    }
    private function transfers(): void
    {
        $warehouses=App\Models\Warehouse::orderBy('id')->get();
        $stock=App\Models\WarehouseStock::where('warehouse_id',$warehouses[0]->id)->where('available_quantity','>',10)->orderBy('id')->first();
        if($stock){
            $destination=App\Models\WarehouseLocation::where('warehouse_id',$warehouses[1]->id)->where('type','bin')->firstOrFail();
            $service=app(App\Services\WarehouseOperationsService::class);
            $transfer=$service->createTransfer(['source_warehouse_id'=>$warehouses[0]->id,'destination_warehouse_id'=>$warehouses[1]->id,'destination_location_id'=>$destination->id,'notes'=>'DC16 synthetic internal replenishment','items'=>[['product_id'=>$stock->product_id,'quantity'=>2]]]);
            $transfer=$service->dispatch($transfer,$this->key('transfer-dispatch'));
            $payload=['idempotency_key'=>$this->key('transfer-receive'),'items'=>$transfer->items->map(fn($i)=>['id'=>$i->id,'accepted_quantity'=>$i->quantity,'damaged_quantity'=>0])->all()];
            $service->receive($transfer,$payload);$before=App\Models\StockMovement::count();$service->receive($transfer->fresh(),$payload);
            if($before!==App\Models\StockMovement::count())throw new RuntimeException('Internal transfer retry duplicated movement');
        }
        $source=App\Models\FinancialAccount::where('type','bank')->firstOrFail();$destination=App\Models\FinancialAccount::where('type','cashbox')->firstOrFail();
        app(App\Services\FinancialAccountService::class)->transfer(['source_account_id'=>$source->id,'destination_account_id'=>$destination->id,'amount'=>'300.00','transfer_date'=>today()->toDateString(),'idempotency_key'=>$this->key('cash-transfer'),'description'=>'DC16 cash register replenishment; not revenue']);
    }
    private function backorder(): void
    {
        $p=App\Models\Product::where('sku','DC16-P001')->firstOrFail();$customer=App\Models\Customer::orderBy('id')->skip(3)->firstOrFail();
        $warehouses=App\Models\Warehouse::orderBy('id')->when($this->day<195,fn($q)=>$q->limit(1))->get();
        foreach($warehouses as $w){
        if(!App\Models\WarehouseStock::where('product_id',$p->id)->where('warehouse_id',$w->id)->where('available_quantity','>',0)->exists())continue;
        $suffix=$this->day<195?'':'-warehouse-'.$w->id;
        $service=app(App\Services\OutboundService::class);$items=[['product_id'=>$p->id,'quantity'=>2000,'unit'=>$p->unit,'unit_price'=>$p->selling_price]];
        $order=$service->create(['customer_id'=>$customer->id,'warehouse_id'=>$w->id,'order_date'=>today()->toDateString(),'requested_delivery_date'=>today()->addDays(12)->toDateString(),'currency'=>'EUR','payment_type'=>'credit','idempotency_key'=>$this->key('backorder'.$suffix),'notes'=>'DC16 explicit backorder request; availability is not a delivery promise.','items'=>$items]);
        app(App\Services\OrderHubService::class)->attachManual($order,['customer_id'=>$customer->id,'items'=>$items,'payment_type'=>'credit']);
        $service->action($order,'confirm',['idempotency_key'=>$this->key('backorder-confirm'.$suffix)]);
        $service->action($order->fresh(),'reserve',['idempotency_key'=>$this->key('backorder-reserve'.$suffix)]);
        $this->state['release_orders'][$this->day+9][]=$order->id;
        }
    }
    private function freezeForecasts(): void
    {
        $models=[];
        foreach(App\Models\Supplier::orderBy('id')->get() as $supplier)app(App\Services\SupplierIntelligenceService::class)->refresh($supplier->id);
        app(App\Services\CustomerSalesIntelligenceService::class)->refresh();
        $customerSnapshot=App\Models\CustomerSalesSnapshot::latest('id')->first();
        $this->state['frozen_relationships'][today()->toDateString()]=['generated_at'=>now()->toIso8601String(),'customer_snapshot_id'=>$customerSnapshot?->id,'customer_evidence_sha256'=>$customerSnapshot?App\Services\InventoryIntelligenceService::artifactHash($customerSnapshot->evidence):null,'supplier_risks'=>App\Models\SupplierDeliveryRisk::orderBy('purchase_order_id')->get(['purchase_order_id','supplier_id','risk','evidence','episode'])->toArray(),'qualification'=>'Point-in-time synthetic evidence; not verified provider/AIS facts or causal claims'];
        foreach([1,41,81,121,241,291] as $i){
            $p=App\Models\Product::where('sku',sprintf('DC16-P%03d',$i))->firstOrFail();
            $training=app(App\Services\InventoryIntelligenceService::class)->train($p->id);
            $cutoff=today()->subDay()->toDateString();
            $history=app(App\Services\InventoryIntelligenceDatasetAdapter::class)->history($p,$cutoff);
            $candidates=App\Models\InventoryForecastModel::where('product_id',$p->id)->where('status','candidate')->whereDate('training_cutoff',$cutoff)->get();
            $artifacts=[];foreach($candidates as $m)$artifacts[(string)$m->horizon]=$m->artifact;
            $forecast=$artifacts?app(App\Contracts\DemandForecastProvider::class)->predict($history,$artifacts):['status'=>'insufficient_data','results'=>[]];
            $models[$p->sku]=['product_id'=>$p->id,'unit'=>$p->unit,'training'=>$training,'forecast'=>$forecast,'history'=>$history,'history_sha256'=>App\Services\InventoryIntelligenceService::artifactHash($history),'model_versions'=>$candidates->pluck('version')->all(),'mode'=>'shadow candidate; not production promotion or operational action'];
        }
        $this->state['frozen_forecasts'][today()->toDateString()]=['cutoff'=>today()->subDay()->toDateString(),'generated_at'=>now()->toIso8601String(),'only_pre_cutoff_records_exist'=>true,'training'=>$models];
    }
}
