<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,DB};
use App\Contracts\DemandForecastProvider;
use App\Models\{Product,Category,Supplier,ProductSupplier,DailySale,DailySalesDay,Invoice,AnalyticsSnapshot,AnalyticsPrediction,InventoryRecommendation,Company,User,PurchaseOrder};
use App\Services\{InventoryIntelligenceService,InventoryIntelligenceDatasetAdapter,InventoryIntelligencePlanner,AnalyticsSalesLedger};

class InventoryIntelligenceTest extends TestCase {
    use RefreshDatabase;
    private function fixture():Product {
        $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();
        $c=Category::create($this->tenantAttributes(['name'=>'Hardware']));
        $p=Product::create($this->tenantAttributes(['name'=>'Door handle','sku'=>'ML-1','category_id'=>$c->id,'unit'=>'pcs','quantity'=>20,'min_quantity'=>5,'purchase_price'=>2,'price'=>5,'selling_price'=>5,'replenishment_review_days'=>7,'created_at'=>now()->subYear()]));
        $p->forceFill(['created_at'=>now()->subYear()])->save();
        $s=Supplier::create($this->tenantAttributes(['name'=>'Factory','is_active'=>true]));
        ProductSupplier::create($this->tenantAttributes(['product_id'=>$p->id,'supplier_id'=>$s->id,'purchase_price'=>2,'currency'=>'EUR','exchange_rate_to_base'=>1,'pack_size'=>5,'minimum_order_quantity'=>10,'usual_lead_time_days'=>3,'is_active'=>true,'is_preferred'=>true]));
        $this->observe($p,1,20);
        return $p;
    }
    private function observe(Product $p,int $days,int $available):void {
        AnalyticsSnapshot::create($this->tenantAttributes(['snapshot_date'=>today()->subDays($days),'entity_type'=>'product','entity_id'=>$p->id,'warehouse_id'=>0,'feature_version'=>'observed-v1','observed_at'=>today()->subDays($days)->endOfDay(),'facts'=>['unit'=>$p->unit,'available'=>$available]]));
    }
    private function sale(Product $p,int $days,float $quantity):DailySale {
        $s=DailySale::create($this->tenantAttributes(['sale_number'=>'ML-'.uniqid(),'sale_date'=>today()->subDays($days),'status'=>'finalized','inventory_applied_at'=>today()->subDays($days)->midDay(),'finalized_at'=>today()->subDays($days)->endOfDay(),'total_amount'=>10,'total_quantity'=>$quantity,'paid_amount'=>10,'created_by'=>Auth::id()]));
        $s->items()->create($this->tenantAttributes(['line_number'=>1,'product_id'=>$p->id,'product_name'=>$p->name,'unit'=>$p->unit,'quantity'=>$quantity,'base_quantity'=>$quantity,'unit_price'=>5,'line_total'=>10]));return $s;
    }
    private function fakeProvider():void {
        $artifact=['version'=>'demand-v1','algorithm'=>'seasonal_mean','parameters'=>[],'training_cutoff'=>today()->subDay()->toDateString()];
        $daily=collect(range(0,29))->map(fn($n)=>['date'=>today()->addDays($n)->toDateString(),'quantity'=>10])->all();
        $metrics=['mae'=>1,'wape'=>10,'bias'=>0,'horizon_mae'=>1,'blocks'=>3,'observations'=>90];
        $result=['status'=>'ready','quality'=>['observed_days'=>120,'calendar_days'=>120,'recent_observed_days'=>90,'recent_calendar_days'=>90,'unknown_days'=>0,'censored_days'=>0],
            'results'=>[['horizon'=>30,'algorithm'=>'seasonal_mean','artifact'=>$artifact,'evaluation'=>$metrics,'comparison'=>['seasonal_mean'=>$metrics],'retained_incumbent'=>false,'daily'=>$daily,'baseline_daily'=>$daily,'total'=>300,'status'=>'evaluated','range_reason'=>'No calibrated interval']]];
        $this->mock(DemandForecastProvider::class,function($mock)use($result){$mock->shouldReceive('train')->andReturn($result);$mock->shouldReceive('predict')->andReturn($result);});
    }
    private function approveCandidate(Product $p):void {
        $m=\App\Models\InventoryForecastModel::where('product_id',$p->id)->where('status','candidate')->firstOrFail();
        app(\App\Services\InventoryLearningService::class)->decide($m->id,'promote',['reason'=>'Reviewed test evidence','expected_production_version'=>null]);
    }
    private function trainForecast(Product $p):void {app(InventoryIntelligenceService::class)->train($p->id);$this->approveCandidate($p);}
    public function test_history_deduplicates_and_distinguishes_censored_unknown_and_zero():void {
        $p=$this->fixture();$sale=$this->sale($p,1,3);
        $invoice=Invoice::create($this->tenantAttributes(['daily_sale_id'=>$sale->id,'invoice_number'=>'ML-I','customer_name'=>'Buyer','status'=>'issued','document_type'=>'invoice','currency'=>'USD','invoice_date'=>$sale->sale_date,'issued_at'=>now(),'total_amount'=>15,'grand_total'=>15]));
        $invoice->items()->create(['product_id'=>$p->id,'description'=>'Door handle','unit'=>'pcs','quantity'=>3,'base_quantity'=>3,'unit_price'=>5,'taxable_amount'=>15,'line_total'=>15]);
        $this->observe($p,2,0);$this->sale($p,2,1);$this->observe($p,3,20);
        DailySalesDay::create($this->tenantAttributes(['sale_date'=>today()->subDays(3),'notes'=>'Recorded day']));
        $other=$p->replicate();$other->sku='ML-OTHER';$other->save();$this->sale($other,3,1);
        $this->observe($p,4,20);DailySalesDay::create($this->tenantAttributes(['sale_date'=>today()->subDays(4),'notes'=>'Notes do not prove zero demand']));
        $history=collect(app(InventoryIntelligenceDatasetAdapter::class)->history($p,today()->subDay()->toDateString())['series'])->keyBy('date');
        $this->assertSame(3.0,$history[today()->subDay()->toDateString()]['demand']);
        $this->assertNull($history[today()->subDays(2)->toDateString()]['demand']);
        $this->assertTrue($history[today()->subDays(2)->toDateString()]['censored']);
        $this->assertSame(0.0,$history[today()->subDays(3)->toDateString()]['demand']);
        $this->assertNull($history[today()->subDays(4)->toDateString()]['demand']);
    }
    public function test_forecast_pr_workflow_revalidates_and_is_idempotent_and_tenant_scoped():void {
        $p=$this->fixture();$this->fakeProvider();
        $this->postJson('/api/analytics/intelligence/products/'.$p->id.'/train')->assertOk()->assertJsonPath('status','candidate_ready');$this->approveCandidate($p);
        $detail=$this->getJson('/api/analytics/intelligence/products/'.$p->id)->assertOk()->json();
        $rec=$detail['recommendation']['id'];$current=$detail['current'];
        $body=['fingerprint'=>$current['fingerprint'],'supplier_id'=>$current['supplier_id'],'unit'=>'pcs'];
        $p->update(['quantity'=>21]);
        $this->postJson('/api/analytics/intelligence/recommendations/'.$rec.'/purchase-request',$body)->assertStatus(409);
        $fresh=$this->getJson('/api/analytics/intelligence/products/'.$p->id)->assertOk()->json();$body['fingerprint']=$fresh['current']['fingerprint'];
        $one=$this->postJson('/api/analytics/intelligence/recommendations/'.$rec.'/purchase-request',$body)->assertOk()->assertJsonPath('purchase_request.status','draft')->json();
        $this->postJson('/api/analytics/intelligence/recommendations/'.$rec.'/purchase-request',$body)->assertOk()->assertJsonPath('reused',true)->assertJsonPath('purchase_request.id',$one['purchase_request']['id']);
        $this->assertDatabaseCount('purchase_requests',1);$this->assertDatabaseCount('purchase_orders',0);
        $outcome=app(\App\Services\IntelligencePerformanceService::class)->outcomes($p)[0];
        $this->assertSame($one['purchase_request']['id'],$outcome['purchase_request_id']);
        $this->assertSame('draft',$outcome['purchase_request_status']);$this->assertFalse($outcome['causal_claim']);$this->assertSame([],$outcome['purchase_orders']);
        app(\App\Services\IntelligencePerformanceService::class)->outcomes($p);
        $this->assertSame(1,\App\Models\ActivityLog::where('action','inventory_intelligence.outcome_observed')->count());
        $other=Company::factory()->create();$user=User::factory()->create(['company_id'=>$other->id,'role'=>'admin','api_token'=>hash('sha256','other-credit-ml')]);
        $this->withHeader('Authorization','Bearer other-credit-ml')->getJson('/api/analytics/intelligence/products/'.$p->id)->assertNotFound();
        $this->postJson('/api/analytics/intelligence/recommendations/'.$rec.'/purchase-request',$body)->assertNotFound();
    }
    public function test_incoming_supply_pack_units_and_uncertain_arrivals():void {
        $p=$this->fixture();$this->fakeProvider();$this->trainForecast($p);
        $p->units()->create(['code'=>'box','label'=>'Box','conversion_mode'=>'fixed','factor_to_base'=>10,'is_active'=>true]);
        $planner=app(InventoryIntelligencePlanner::class);$daily=AnalyticsPrediction::firstOrFail()->value['daily'];$suppliers=$planner->suppliers($p);
        $r=$planner->calculate($p,$daily,$suppliers,null,'box');$this->assertGreaterThan(0,$r['quantity']);$this->assertEquals(round($r['quantity']),$r['quantity']);
        $po=PurchaseOrder::create($this->tenantAttributes(['supplier_id'=>$suppliers[0]['supplier_id'],'po_number'=>'ML-PO','status'=>'ordered','ordered_at'=>today(),'expected_at'=>today()->addDay(),'currency'=>'EUR']));
        $po->items()->create(['product_id'=>$p->id,'description'=>$p->name,'unit'=>'pcs','quantity'=>1000,'base_quantity'=>1000,'unit_price'=>2,'line_total'=>2000]);
        $r=$planner->calculate($p,$daily,$suppliers);$this->assertNull($r['stockout_date']);$this->assertEquals(0,$r['quantity']);
        $po->update(['expected_at'=>today()->subDay()]);$r=$planner->calculate($p,$daily,$suppliers);$this->assertNotNull($r['stockout_date']);$this->assertEquals(1000,$r['uncertain_incoming']);
    }
    public function test_missing_python_is_nonfatal_and_no_fake_forecast_is_written():void {
        $p=$this->fixture();$this->mock(DemandForecastProvider::class,fn($m)=>$m->shouldReceive('train')->andThrow(new \App\Exceptions\ForecastUnavailableException('Local forecasting is unavailable.')));
        $this->postJson('/api/analytics/intelligence/products/'.$p->id.'/train')->assertStatus(503);
        $this->getJson('/api/analytics/intelligence')->assertOk();$this->assertDatabaseCount('analytics_predictions',0);
    }
    public function test_expired_forecast_outcome_excludes_censored_days():void {
        $p=$this->fixture();for($i=1;$i<=30;$i++){if($i>1)$this->observe($p,$i,20);$this->sale($p,$i,7);}
        $this->fakeProvider();$this->trainForecast($p);$forecast=AnalyticsPrediction::firstOrFail();
        $daily=collect(range(30,1))->map(fn($d)=>['date'=>today()->subDays($d)->toDateString(),'quantity'=>10])->all();
        $forecast->update(['generated_at'=>today()->subDays(30),'value'=>array_merge($forecast->value,['daily'=>$daily,'training_cutoff'=>today()->subDays(31)->toDateString(),'cutoff'=>today()->subDays(31)->toDateString()])]);
        $this->assertSame(1,app(InventoryIntelligenceService::class)->evaluate());
        $this->assertEquals(3,$forecast->fresh()->evaluation['mae']);$this->assertTrue($forecast->fresh()->evaluation['eligible']);
        $this->assertSame(0,app(InventoryIntelligenceService::class)->evaluate());
        $accuracy=app(InventoryIntelligenceService::class)->companyAccuracy(30);
        $this->assertEquals(3,$accuracy['groups'][0]['mae']);$this->assertEquals(100*3/7,$accuracy['groups'][0]['wape']);
    }
    public function test_late_posted_sales_are_not_future_features_and_new_product_has_safe_empty_history():void {
        $p=$this->fixture();$sale=$this->sale($p,10,6);$sale->update(['inventory_applied_at'=>today()->subDays(2)->midDay()]);
        $rows=app(AnalyticsSalesLedger::class)->rows(today()->subDays(20)->toDateString(),today()->subDay()->toDateString(),true);
        $this->assertSame(today()->subDays(2)->toDateString(),$rows->first()['date']);
        $past=app(AnalyticsSalesLedger::class)->rows(today()->subDays(20)->toDateString(),today()->subDays(3)->toDateString(),true);
        $this->assertCount(0,$past);
        $p->forceFill(['created_at'=>now()])->save();$h=app(InventoryIntelligenceDatasetAdapter::class)->history($p,today()->subDay()->toDateString());
        $this->assertSame([],$h['series']);$this->assertArrayHasKey('cutoff',$h);
    }
    public function test_permissions_preserve_403_and_hide_supplier_financial_values():void {
        $p=$this->fixture();$this->fakeProvider();$this->trainForecast($p);
        $role=\App\Models\Role::where('slug','admin')->firstOrFail();
        $role->permissions()->detach(\App\Models\Permission::where('slug','analytics.finance')->value('id'));
        \Illuminate\Support\Facades\Cache::forget('role_permissions:admin');
        $this->postJson('/api/analytics/intelligence/products/'.$p->id.'/train')->assertForbidden();
        $this->getJson('/api/analytics/intelligence/products/'.$p->id)->assertOk()->assertJsonPath('current.estimated_cost',null)->assertJsonPath('suppliers.0.purchase_price',null);
        $this->getJson('/api/analytics/intelligence')->assertOk()->assertJsonPath('rows.0.recommendation.explanation.estimated_cost',null);
    }
    public function test_real_local_pipeline_persists_evaluated_models_and_reuses_current_forecasts():void {
        $p=$this->fixture();for($i=1;$i<=120;$i++){if($i>1)$this->observe($p,$i,20);$this->sale($p,$i,2);}
        $response=$this->postJson('/api/analytics/intelligence/products/'.$p->id.'/train')->assertOk()->assertJsonPath('status','candidate_ready');
        $this->assertDatabaseCount('inventory_forecast_models',3);$this->assertDatabaseCount('analytics_predictions',0);
        $this->approveCandidate($p);
        $seven=$this->getJson('/api/analytics/intelligence/products/'.$p->id.'?horizon=7')->assertOk()->assertJsonPath('model.algorithm','seasonal_mean')->assertJsonPath('prediction.value.quality.observed_days',120)->json();
        $this->assertEquals(0,$seven['model']['metrics']['mae']);$this->assertEquals(14,$seven['prediction']['value']['total']);
        $this->postJson('/api/analytics/intelligence/products/'.$p->id.'/train')->assertOk()->assertJsonPath('reused',true);
        $this->assertDatabaseCount('analytics_predictions',1);
        $p->update(['unit'=>'m']);
        $this->postJson('/api/analytics/intelligence/products/'.$p->id.'/train')->assertOk()->assertJsonPath('status','insufficient_data');
        $this->getJson('/api/analytics/intelligence/products/'.$p->id.'?horizon=7')->assertOk()->assertJsonPath('stale',true);
        $this->assertDatabaseCount('analytics_predictions',1);
    }
}
