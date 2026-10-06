<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use App\Models\{Category,Product,DailySale,Invoice,Customer,Supplier,PurchaseOrder,GoodsReceipt,AnalyticsSnapshot,AnalyticsDataset,AnalyticsDatasetRow,Company,User};
use App\Services\{AnalyticsSalesLedger,AnalyticsService,AnalyticsDataService,AnalyticsPeriod};

class AnalyticsFoundationTest extends TestCase {
    use RefreshDatabase;
    private function fixture():Product {
        $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();
        $c=Category::create($this->tenantAttributes(['name'=>'Hardware']));
        return Product::create($this->tenantAttributes(['name'=>'Handle','sku'=>'AN-1','category_id'=>$c->id,'unit'=>'pcs','quantity'=>100,'min_quantity'=>10,'purchase_price'=>2,'weighted_average_cost'=>2,'inventory_value'=>200,'selling_price'=>5,'price'=>5]));
    }
    private function sale(Product $p,int $days,int $qty=2):DailySale {
        $s=DailySale::create($this->tenantAttributes(['sale_number'=>'AN-'.uniqid(),'sale_date'=>today()->subDays($days),'status'=>'finalized','inventory_applied_at'=>now(),'total_amount'=>$qty*5,'total_quantity'=>$qty,'paid_amount'=>$qty*5,'created_by'=>Auth::id()]));
        $s->items()->create($this->tenantAttributes(['line_number'=>1,'product_id'=>$p->id,'product_name'=>$p->name,'unit'=>'pcs','quantity'=>$qty,'base_quantity'=>$qty,'unit_price'=>5,'unit_cost'=>2,'line_total'=>$qty*5,'cost_total'=>$qty*2,'gross_profit'=>$qty*3]));return $s;
    }
    public function test_canonical_sales_deduplicate_invoice_and_order_and_windows_are_correct():void {
        $p=$this->fixture();$sale=$this->sale($p,2,2);$this->sale($p,20,3);$this->sale($p,60,4);
        $i=Invoice::create($this->tenantAttributes(['invoice_number'=>'AN-INV','daily_sale_id'=>$sale->id,'customer_name'=>'Buyer','status'=>'issued','document_type'=>'invoice','currency'=>'EUR','invoice_date'=>$sale->sale_date,'issued_at'=>now(),'total_amount'=>10,'grand_total'=>10,'taxable_total'=>10]));
        $i->items()->create(['product_id'=>$p->id,'description'=>$p->name,'unit'=>'pcs','quantity'=>2,'base_quantity'=>2,'unit_price'=>5,'taxable_amount'=>10,'line_total'=>10,'cost_total'=>4]);
        $order=\App\Models\SalesOrder::create($this->tenantAttributes(['order_number'=>'AN-ORDER','idempotency_key'=>'an-order','request_fingerprint'=>'analytics-order','order_date'=>today()->subDays(3),'created_at'=>today()->subDays(3),'completed_at'=>today()->subDay(),'status'=>'delivered','total_amount'=>10,'currency'=>'EUR','payment_type'=>'cash','created_by'=>Auth::id()]));
        \App\Models\OutboundDispatch::create($this->tenantAttributes(['sales_order_id'=>$order->id,'daily_sale_id'=>$sale->id,'reference'=>'AN-DISPATCH','status'=>'delivered','dispatched_at'=>today()->subDays(2),'delivered_at'=>today()->subDay(),'total_amount'=>10]));
        $report=$this->getJson('/api/analytics/sales?period=90d')->assertOk()->assertJsonPath('metrics.revenue','45.00')->assertJsonPath('metrics.sales_count',3)->assertJsonPath('metrics.gross_profit','27.00');
        $row=app(AnalyticsService::class)->productRows()[0];$this->assertSame(2.0,$row['sales_7d']);$this->assertSame(5.0,$row['sales_30d']);$this->assertSame(9.0,$row['sales_90d']);
        $this->assertSame('25.00',$row['revenue_30d']);$this->assertSame(100.0,(float)$p->fresh()->quantity);
        $this->getJson('/api/analytics/orders?period=7d')->assertOk()->assertJsonPath('rows.0.delivery_days',1)->assertJsonPath('rows.0.fulfillment_days',1);
    }
    public function test_periods_and_all_workspaces_reuse_authoritative_sources():void {
        $p=$this->fixture();$this->sale($p,1);
        foreach(array_keys(AnalyticsService::AREAS) as $area)$this->getJson('/api/analytics/'.$area.'?period=30d')->assertOk();
        foreach(['today','yesterday','7d','30d','90d','month','last_month','quarter','year'] as $period){$r=AnalyticsPeriod::resolve(['period'=>$period]);$this->assertNotEmpty($r['previous_from']);}
        $this->getJson('/api/analytics/sales?period=custom&from=2020-01-01&to=2030-01-01')->assertUnprocessable();
    }
    public function test_supplier_actual_delay_and_promised_lead_time():void {
        $this->fixture();$s=Supplier::create($this->tenantAttributes(['name'=>'Factory','is_active'=>true]));
        $o=PurchaseOrder::create($this->tenantAttributes(['supplier_id'=>$s->id,'po_number'=>'AN-PO','status'=>'received','ordered_at'=>today()->subDays(20),'expected_at'=>today()->subDays(10),'received_at'=>today()->subDays(7),'total_amount'=>100,'total_amount_eur'=>100,'currency'=>'EUR','exchange_rate'=>1]));
        $w=\App\Models\Warehouse::create($this->tenantAttributes(['name'=>'Main','code'=>'MAIN','is_active'=>true]));
        GoodsReceipt::create($this->tenantAttributes(['warehouse_id'=>$w->id,'purchase_order_id'=>$o->id,'receipt_number'=>'AN-GR','idempotency_key'=>'an-gr','received_at'=>today()->subDays(7),'status'=>'completed','received_by'=>Auth::id()]));
        $r=app(AnalyticsService::class)->supplierRows()[0];$this->assertEquals(13,$r['average_lead_time']);$this->assertEquals(10,$r['promised_lead_time']);$this->assertEquals(3,$r['average_delay']);$this->assertEquals(0,$r['on_time_rate']);
    }
    public function test_snapshots_are_idempotent_and_historical_features_do_not_leak_future_values():void {
        $this->travelTo(now()->subDays(35)->startOfDay());$p=$this->fixture();$this->sale($p,2,2);$date=today()->toDateString();
        $this->postJson('/api/analytics/snapshots')->assertOk();$count=AnalyticsSnapshot::count();$snapshot=AnalyticsSnapshot::where('entity_type','product')->firstOrFail();$facts=$snapshot->facts;
        $this->postJson('/api/analytics/snapshots')->assertOk()->assertJsonPath('reused',true);$this->assertSame($count,AnalyticsSnapshot::count());
        $this->travel(15)->days();$this->sale($p,0,7);$p->update(['quantity'=>900]);$this->travel(21)->days();
        $this->assertSame($facts,$snapshot->fresh()->facts);$this->assertEquals(100,$snapshot->fresh()->facts['available']);
        $this->getJson('/api/analytics/features?entity_type=product&entity_id='.$p->id.'&date='.$date)->assertOk()->assertJsonPath('features.product_sales_7d',2);
        $d=$this->postJson('/api/analytics/datasets',['from'=>$date,'to'=>$date])->assertCreated()->assertJsonPath('labelled_count',1)->json();
        $values=AnalyticsDatasetRow::where('analytics_dataset_id',$d['id'])->firstOrFail()->values;$this->assertEquals(2,$values['product_sales_7d']);$this->assertEquals(7,$values['target_demand_next_30d']);
        $this->get('/api/analytics/datasets/'.$d['id'].'/export?format=csv')->assertOk()->assertHeader('content-type','text/csv; charset=UTF-8');
        $this->get('/api/analytics/datasets/'.$d['id'].'/export?format=json')->assertOk();
        $this->assertDatabaseCount('analytics_predictions',0);
        try{app(AnalyticsDataService::class)->capture($date);$this->fail('Historical reconstruction allowed');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('date',$e->errors());}
    }
    public function test_unmatured_labels_are_null_and_cross_tenant_access_is_denied():void {
        $p=$this->fixture();$this->postJson('/api/analytics/snapshots')->assertOk();$date=today()->toDateString();
        $d=$this->postJson('/api/analytics/datasets',['from'=>$date,'to'=>$date])->assertCreated()->assertJsonPath('labelled_count',0)->json();
        $this->assertNull(AnalyticsDatasetRow::first()->values['target_demand_next_30d']);
        $company=Company::factory()->create();User::factory()->create(['company_id'=>$company->id,'role'=>'admin','api_token'=>hash('sha256','other-analytics'),'email_verified_at'=>now()]);
        $this->withToken('other-analytics')->get('/api/analytics/datasets/'.$d['id'].'/export?format=json')->assertNotFound();
        $this->getJson('/api/analytics/features?entity_type=product&entity_id='.$p->id.'&date='.$date)->assertNotFound();
        $this->getJson('/api/analytics/inventory')->assertOk()->assertJsonPath('metrics.products',0);
    }
    public function test_quality_issues_emit_once_and_do_not_repair_source_data():void {
        $this->fixture();$s=Supplier::create($this->tenantAttributes(['name'=>'Dates supplier']));
        $o=PurchaseOrder::create($this->tenantAttributes(['supplier_id'=>$s->id,'po_number'=>'BAD-DATE','status'=>'received','ordered_at'=>today(),'received_at'=>today()->subDays(3),'total_amount'=>1,'total_amount_eur'=>1,'currency'=>'EUR']));
        app(AnalyticsDataService::class)->refreshIssues();app(AnalyticsDataService::class)->refreshIssues();
        $this->assertDatabaseHas('analytics_issues',['code'=>'receipt_before_order','entity_id'=>$o->id,'severity'=>'problem']);
        $this->assertSame(1,\App\Models\BusinessEvent::where('event_type','analytics.data_quality_problem')->count());
        $this->assertEquals(today()->subDays(3)->toDateString(),$o->fresh()->received_at->toDateString());
        $this->getJson('/api/action-center')->assertOk();
        $this->actingAsApiUser('staff')->getJson('/api/analytics/overview')->assertForbidden();
    }
    public function test_today_sales_refunds_precision_and_customer_metrics():void {
        $p=$this->fixture();$c=Customer::create($this->tenantAttributes(['name'=>'Analytics customer','current_debt'=>'10.10','current_credit'=>'0.10','credit_limit'=>100]));
        $sale=$this->sale($p,0,2);$sale->update(['customer_id'=>$c->id,'customer_name'=>$c->name]);
        $return=\App\Models\InventoryReturn::create($this->tenantAttributes(['return_number'=>'AN-RET','type'=>'customer','status'=>'completed','customer_id'=>$c->id,'daily_sale_id'=>$sale->id,'financial_resolution'=>'cash_refund','financial_amount'=>'5.00','currency'=>'EUR','reason'=>'Test return','idempotency_key'=>'AN-RET','completed_at'=>now()]));
        $warehouse=\App\Models\Warehouse::create($this->tenantAttributes(['name'=>'Returns','code'=>'RET','is_active'=>true]));
        $return->items()->create(['warehouse_id'=>$warehouse->id,'product_id'=>$p->id,'daily_sale_item_id'=>$sale->items->first()->id,'quantity'=>1,'processed_quantity'=>1,'unit'=>'pcs','unit_cost'=>2,'line_value'=>5,'condition'=>'good','stock_state'=>'available']);
        $this->getJson('/api/analytics/sales?period=today')->assertOk()->assertJsonPath('metrics.revenue','5.00')->assertJsonPath('metrics.return_value','5.00')->assertJsonPath('metrics.sales_count',1)->assertJsonPath('metrics.gross_profit','3.00');
        $this->getJson('/api/analytics/customers')->assertOk()->assertJsonPath('rows.0.exposure','10.00');
        $this->postJson('/api/analytics/snapshots')->assertOk();
        $this->assertDatabaseHas('analytics_snapshots',['entity_type'=>'customer','entity_id'=>$c->id]);
        $this->getJson('/api/analytics/inventory?period=today')->assertOk()->assertJsonPath('trend_metric','stock_value')->assertJsonPath('trend.0.stock_value','200.00');
    }
    public function test_finance_permissions_are_enforced_within_operational_analytics():void {
        $this->fixture();$role=\App\Models\Role::where('slug','admin')->firstOrFail();
        $role->permissions()->detach(\App\Models\Permission::where('slug','analytics.finance')->value('id'));
        \Illuminate\Support\Facades\Cache::forget('role_permissions:admin');
        $this->getJson('/api/analytics/sales')->assertForbidden();$this->postJson('/api/analytics/snapshots')->assertForbidden();
        $this->getJson('/api/analytics/inventory')->assertOk()->assertJsonMissingPath('metrics.stock_value')->assertJsonMissingPath('rows.0.revenue_30d');
        $this->getJson('/api/analytics/logistics')->assertOk()->assertJsonMissingPath('metrics.landed_cost');
    }
    public function test_observations_cannot_be_rewritten_and_changed_units_invalidate_labels():void {
        $this->travelTo(now()->subDays(35));$p=$this->fixture();$date=today()->toDateString();$this->postJson('/api/analytics/snapshots')->assertOk();
        $snapshot=AnalyticsSnapshot::where('entity_type','product')->firstOrFail();
        try{$snapshot->update(['facts'=>['available'=>999]]);$this->fail('Snapshot changed');}catch(\LogicException $e){$this->assertStringContainsString('immutable',$e->getMessage());}
        $this->travel(36)->days();$p->update(['unit'=>'m']);
        $d=$this->postJson('/api/analytics/datasets',['from'=>$date,'to'=>$date])->assertCreated()->assertJsonPath('labelled_count',0)->json();
        $this->assertSame('unit_unverifiable',AnalyticsDatasetRow::where('analytics_dataset_id',$d['id'])->firstOrFail()->values['label_status']);
        $tool=app(\App\Services\AimsToolRegistry::class)->execute('get_product_performance',['product_id'=>$p->id]);
        $this->assertNotEmpty($tool);
    }
}
