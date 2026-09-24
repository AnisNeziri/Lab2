<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Models\{Category,Supplier,Product,Customer,Warehouse,SalesOrder,OutboundAllocation,JournalEntry,User,Company};

class OutboundFulfillmentTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(string $unit='pcs',int $stock=20): array
    {
        $this->actingAsApiUser();
        $cat=Category::create($this->tenantAttributes(['name'=>'Outbound']));$supplier=Supplier::create($this->tenantAttributes(['name'=>'Supplier']));
        $warehouse=$this->postJson('/api/warehouses',['name'=>'Main','code'=>'MAIN','is_default'=>true])->assertCreated()->json();
        $p=$this->postJson('/api/products',['name'=>'Fulfillment test','sku'=>'OUT-1','barcode'=>'123456789012','category_id'=>$cat->id,'supplier_id'=>$supplier->id,'default_warehouse_id'=>$warehouse['id'],'unit'=>$unit,'quantity'=>$stock,'min_quantity'=>1,'purchase_price'=>2,'selling_price'=>5,'price'=>5])->assertCreated()->json();
        $c=Customer::create($this->tenantAttributes(['name'=>'Buyer','current_debt'=>0,'current_credit'=>0]));
        return [$p,$c];
    }
    private function order($p,$c,$quantity=4,$payment='cash'): array
    {
        return $this->postJson('/api/sales-orders',['customer_id'=>$c->id,'order_date'=>now()->toDateString(),'payment_type'=>$payment,'idempotency_key'=>(string)Str::uuid(),'items'=>[['product_id'=>$p['id'],'quantity'=>$quantity,'unit'=>$p['unit'],'unit_price'=>5]]])->assertCreated()->json();
    }
    private function act($o,$action,$data=[],$status=200): array
    {
        return $this->postJson('/api/sales-orders/'.$o['id'].'/'.$action,['idempotency_key'=>(string)Str::uuid(),...$data])->assertStatus($status)->json();
    }
    private function packed($p,$c,$quantity=4,$payment='cash'): array
    {
        $o=$this->order($p,$c,$quantity,$payment);$o=$this->act($o,'confirm');$o=$this->act($o,'reserve');$o=$this->act($o,'allocate');$a=$o['allocations'][0];
        $o=$this->act($o,'pick',['allocation_id'=>$a['id'],'location_id'=>$a['location_id'],'inventory_lot_id'=>$a['inventory_lot_id'],'quantity'=>$a['quantity'],'barcode'=>'123456789012']);
        return $this->act($o,'pack',['items'=>[['allocation_id'=>$a['id'],'quantity'=>$a['quantity']]]]);
    }
    public function test_full_cycle_issues_stock_once_and_returns_to_quarantine_with_credit():void
    {
        [$p,$c]=$this->fixture();$o=$this->packed($p,$c);
        $this->assertEquals(20,Product::find($p['id'])->quantity);
        $key=(string)Str::uuid();$data=['package_ids'=>[$o['packages'][0]['id']],'idempotency_key'=>$key];
        $o=$this->act($o,'dispatch',$data);$this->act($o,'dispatch',$data);
        $this->assertEquals(16,Product::find($p['id'])->quantity);$this->assertCount(1,$o['dispatches']);
        $o=$this->act($o,'delivery',['dispatch_id'=>$o['dispatches'][0]['id'],'recipient'=>'Buyer']);$this->assertSame('delivered',$o['status']);
        $o=$this->act($o,'return',['stage'=>'requested','allocation_id'=>$o['allocations'][0]['id'],'quantity'=>2,'reason'=>'Damaged package']);$id=$o['returns'][0]['id'];
        $o=$this->act($o,'return',['stage'=>'authorized','return_id'=>$id]);$o=$this->act($o,'return',['stage'=>'received','return_id'=>$id]);$o=$this->act($o,'return',['stage'=>'resolved','return_id'=>$id,'disposition'=>'quarantine']);
        $this->assertEquals(18,Product::find($p['id'])->quantity);$this->assertEquals(2,\App\Models\WarehouseStock::where('product_id',$p['id'])->sum('quarantine_quantity'));
        $this->assertEquals(10,$c->fresh()->current_credit);$this->assertSame('resolved',$o['returns'][0]['status']);
    }
    public function test_partial_reservation_and_release_never_over_reserve_or_reduce_physical_stock():void
    {
        [$p,$c]=$this->fixture();$one=$this->act($this->order($p,$c,15),'confirm');$two=$this->act($this->order($p,$c,15),'confirm');
        $one=$this->act($one,'reserve');$two=$this->act($two,'reserve');$this->assertEquals(5,$two['items'][0]['reserved_quantity']);
        $this->assertEquals(20,Product::find($p['id'])->quantity);$this->act($one,'cancel');
        $two=$this->act($two,'reserve');$this->assertEquals(15,$two['items'][0]['reserved_quantity']);
    }
    public function test_verification_and_quantity_limits_are_server_enforced():void
    {
        [$p,$c]=$this->fixture();$o=$this->act($this->order($p,$c),'confirm');$o=$this->act($o,'reserve');$o=$this->act($o,'allocate');$a=$o['allocations'][0];
        $this->act($o,'pick',['allocation_id'=>$a['id'],'quantity'=>4,'barcode'=>'WRONG'],422);
        $this->act($o,'pick',['allocation_id'=>$a['id'],'quantity'=>5,'manual_verification'=>true],422);
        $o=$this->act($o,'pick',['allocation_id'=>$a['id'],'quantity'=>2,'manual_verification'=>true,'reason'=>'Missing stock']);
        $this->act($o,'pack',['items'=>[['allocation_id'=>$a['id'],'quantity'=>3]]],422);
        $o=$this->act($o,'pack',['items'=>[['allocation_id'=>$a['id'],'quantity'=>2]]]);$o=$this->act($o,'dispatch',['package_ids'=>[$o['packages'][0]['id']]]);
        $this->assertSame('partially_dispatched',$o['status']);$this->assertEquals(18,Product::find($p['id'])->quantity);
    }
    public function test_credit_commitments_and_exact_override_are_enforced():void
    {
        [$p,$c]=$this->fixture();$c->update(['credit_limit'=>10]);$o=$this->order($p,$c,4,'credit');$this->act($o,'confirm',[],422);
        $o=$this->act($o,'credit-override',['reason'=>'Approved buyer']);$id=$o['approval']['id'];$again=$this->act($o,'credit-override',['reason'=>'Approved buyer']);$this->assertSame($id,$again['approval']['id']);
        $this->postJson('/api/approvals/'.$id.'/decision',['decision'=>'approved'])->assertUnprocessable();
        User::factory()->create(['company_id'=>$c->company_id,'role'=>'admin','api_token'=>hash('sha256','approver'),'email_verified_at'=>now()]);
        $this->withToken('approver')->postJson('/api/approvals/'.$id.'/decision',['decision'=>'approved'])->assertOk();$o=$this->act($o,'confirm');$this->assertEquals(20,$o['credit']['committed_exposure']);
        $o=$this->act($o,'reserve');$o=$this->act($o,'allocate');$a=$o['allocations'][0];$o=$this->act($o,'pick',['allocation_id'=>$a['id'],'quantity'=>4,'manual_verification'=>true]);$o=$this->act($o,'pack',['items'=>[['allocation_id'=>$a['id'],'quantity'=>4]]]);$o=$this->act($o,'dispatch',['package_ids'=>[$o['packages'][0]['id']]]);
        $this->assertEquals(20,$c->fresh()->current_debt);$this->assertEquals(0,$o['committed_amount']);
    }
    public function test_fractional_metres_and_prepaid_advance():void
    {
        [$p,$c]=$this->fixture('m',30);$c->update(['current_credit'=>100]);$o=$this->packed($p,$c,17.5,'prepaid');$o=$this->act($o,'dispatch',['package_ids'=>[$o['packages'][0]['id']]]);
        $this->assertEquals(12.5,Product::find($p['id'])->quantity);$this->assertEquals(12.5,$c->fresh()->current_credit);$this->assertEquals(0,$c->fresh()->current_debt);
    }
    public function test_worker_cannot_confirm_or_dispatch_and_other_tenant_cannot_view():void
    {
        [$p,$c]=$this->fixture();$o=$this->order($p,$c);
        User::factory()->create(['company_id'=>$c->company_id,'role'=>'staff','api_token'=>hash('sha256','picker'),'email_verified_at'=>now()]);
        $this->withToken('picker')->getJson('/api/sales-orders/'.$o['id'])->assertOk();$this->act($o,'confirm',[],403);
        User::factory()->create(['company_id'=>Company::factory()->create()->id,'role'=>'admin','api_token'=>hash('sha256','foreign'),'email_verified_at'=>now()]);$this->withToken('foreign')->getJson('/api/sales-orders/'.$o['id'])->assertNotFound();
    }

    public function test_partial_delivery_validates_source_precision_and_cannot_erase_delivery():void
    {
        [$p,$c]=$this->fixture();$o=$this->packed($p,$c);
        $o=$this->act($o,'dispatch',['package_ids'=>[$o['packages'][0]['id']]]);$d=$o['dispatches'][0];$pi=$d['packages'][0]['items'][0];
        $this->act($o,'delivery',['dispatch_id'=>$d['id'],'recipient'=>'Buyer','items'=>[['package_item_id'=>999999,'quantity'=>1]]],422);
        $this->act($o,'delivery',['dispatch_id'=>$d['id'],'recipient'=>'Buyer','items'=>[['package_item_id'=>$pi['id'],'quantity'=>0.5]]],422);
        $o=$this->act($o,'delivery',['dispatch_id'=>$d['id'],'recipient'=>'Buyer','items'=>[['package_item_id'=>$pi['id'],'quantity'=>1]]]);
        $this->assertSame('partially_delivered',$o['status']);$this->assertEquals(1,$o['items'][0]['delivered_quantity']);
        $this->act($o,'delivery',['dispatch_id'=>$d['id'],'recipient'=>'Buyer','items'=>[['package_item_id'=>$pi['id'],'quantity'=>0]]],422);
        $o=$this->act($o,'delivery',['dispatch_id'=>$d['id'],'recipient'=>'Buyer']);
        $this->assertSame('delivered',$o['status']);$this->assertEquals(16,Product::find($p['id'])->quantity);
        $this->act($o,'delivery',['dispatch_id'=>$d['id'],'failure_reason'=>'Must use returns'],422);
    }

    public function test_credit_order_uses_unused_advance_before_checking_limit():void
    {
        [$p,$c]=$this->fixture();$c->update(['current_credit'=>15,'credit_limit'=>5]);
        $o=$this->order($p,$c,4,'credit');$this->assertEquals('5.00',$o['credit']['projected_exposure']);
        $o=$this->act($o,'confirm');$this->assertEquals('5.00',$o['credit']['projected_exposure']);
        $this->assertEquals('0.00',$o['credit']['current_exposure']);
        $next=$this->order($p,$c,1,'credit');$this->act($next,'confirm',[],422);
    }

    public function test_return_uses_exact_original_dispatch_line_not_first_matching_product():void
    {
        [$p,$c]=$this->fixture();$o=$this->act($this->order($p,$c,6),'confirm');$o=$this->act($o,'reserve');$o=$this->act($o,'allocate');$a=$o['allocations'][0];
        $o=$this->act($o,'pick',['allocation_id'=>$a['id'],'quantity'=>6,'manual_verification'=>true]);
        foreach([2,4] as $quantity){
            $o=$this->act($o,'pack',['items'=>[['allocation_id'=>$a['id'],'quantity'=>$quantity]]]);
            $package=collect($o['packages'])->firstWhere('outbound_dispatch_id',null);
            $o=$this->act($o,'dispatch',['package_ids'=>[$package['id']]]);$d=collect($o['dispatches'])->last();
            $o=$this->act($o,'delivery',['dispatch_id'=>$d['id'],'recipient'=>'Buyer']);
        }
        $this->act($o,'return',['stage'=>'requested','allocation_id'=>$a['id'],'quantity'=>4,'reason'=>'Return second delivery'],422);
        $source=$d['packages'][0]['items'][0];
        $o=$this->act($o,'return',['stage'=>'requested','allocation_id'=>$a['id'],'package_item_id'=>$source['id'],'quantity'=>4,'reason'=>'Return second delivery']);$r=collect($o['returns'])->last();
        $this->act($o,'return',['stage'=>'requested','allocation_id'=>$a['id'],'package_item_id'=>$source['id'],'quantity'=>1,'reason'=>'Duplicate'],422);
        foreach(['authorized','received','resolved'] as $stage)$o=$this->act($o,'return',['return_id'=>$r['id'],'stage'=>$stage,'disposition'=>'quarantine']);
        $return=\App\Models\InventoryReturn::findOrFail($o['returns'][0]['inventory_return_id']);
        $this->assertEquals($d['daily_sale_id'],$return->daily_sale_id);
        $this->assertEquals($source['daily_sale_item_id'],$return->items->first()->daily_sale_item_id);
        $this->assertEquals(18,Product::find($p['id'])->quantity);$this->assertEquals(20,$c->fresh()->current_credit);
    }

    public function test_draft_edit_assignment_wave_and_context_filters():void
    {
        [$p,$c]=$this->fixture();$o=$this->order($p,$c);
        $o=$this->act($o,'edit',['customer_id'=>$c->id,'order_date'=>now()->toDateString(),'requested_delivery_date'=>now()->subDay()->toDateString(),'payment_type'=>'cash','items'=>[['product_id'=>$p['id'],'quantity'=>3,'unit'=>'pcs','unit_price'=>5]]]);
        $this->assertEquals(15,$o['total_amount']);$this->assertCount(1,$o['items']);
        $o=$this->act($o,'confirm');$o=$this->act($o,'reserve');
        $picker=User::factory()->create(['company_id'=>$c->company_id,'role'=>'staff']);
        $o=$this->act($o,'allocate',['assigned_to'=>$picker->id]);$task=$o['tasks'][0];
        $this->assertEquals($picker->id,$task['assigned_to']);
        $o=$this->act($o,'assign',['task_id'=>$task['id'],'assigned_to'=>null]);$this->assertNull($o['tasks'][0]['assigned_to']);
        $wave=$this->postJson('/api/fulfillment/waves',['warehouse_id'=>$task['warehouse_id'],'task_ids'=>[$task['id']]])->assertCreated()->json();
        $this->getJson('/api/sales-orders?wave='.$wave['id'].'&product_id='.$p['id'])->assertOk()->assertJsonPath('total',1);
        $this->getJson('/api/sales-orders?view=late')->assertOk()->assertJsonPath('total',1);
        $this->getJson('/api/sales-orders?view=returns')->assertOk()->assertJsonPath('total',0);
        $this->postJson('/api/fulfillment/waves',['warehouse_id'=>$task['warehouse_id'],'task_ids'=>[$task['id']]])->assertUnprocessable();
    }

    public function test_fefo_quarantine_expiry_and_fifo_candidates():void
    {
        [$p,$c]=$this->fixture('pcs',0);$product=Product::findOrFail($p['id']);$product->update(['tracking_mode'=>'batch_expiry','fefo_enabled'=>true]);
        $stock=app(\App\Services\StockMovementService::class);
        foreach(['LATE'=>30,'EARLY'=>10,'EXPIRED'=>1] as $lot=>$days){
            $stock->store(['product_id'=>$product->id,'warehouse_id'=>$product->default_warehouse_id,'type'=>'in','quantity'=>3,'stock_state'=>'available','reason'=>'Test receipt','movement_code'=>'import_adjustment_in','idempotency_key'=>(string)Str::uuid(),'trace_allocations'=>[['lot_number'=>$lot,'expiry_at'=>now()->addDays($days)->toDateString(),'quantity'=>3]]]);
        }
        \App\Models\InventoryLot::where('lot_number','EXPIRED')->update(['expiry_at'=>now()->subDay()]);
        $o=$this->act($this->order($p,$c,4),'confirm');$o=$this->act($o,'reserve');
        $this->assertEquals('EARLY',$o['allocations'][0]['lot']['lot_number']);$this->assertEquals(3,$o['allocations'][0]['quantity']);
        $this->assertEquals('LATE',$o['allocations'][1]['lot']['lot_number']);
        $this->act($o,'cancel');
        $early=\App\Models\InventoryLot::where('lot_number','EARLY')->firstOrFail();
        $stock->transitionState(['product_id'=>$product->id,'warehouse_id'=>$product->default_warehouse_id,'quantity'=>3,'from_state'=>'available','to_state'=>'quarantine','reason'=>'Quality hold','idempotency_key'=>(string)Str::uuid(),'trace_allocations'=>[['inventory_lot_id'=>$early->id,'quantity'=>3]]]);
        $new=$this->act($this->order($p,$c,4),'confirm');$new=$this->act($new,'reserve');$this->assertCount(1,$new['allocations']);$this->assertEquals(3,$new['items'][0]['reserved_quantity']);
    }

    public function test_full_backup_restores_outbound_stock_and_dispatch_return_relationships():void
    {
        [$p,$c]=$this->fixture();$o=$this->packed($p,$c);$o=$this->act($o,'dispatch',['package_ids'=>[$o['packages'][0]['id']]]);$o=$this->act($o,'delivery',['dispatch_id'=>$o['dispatches'][0]['id'],'recipient'=>'Buyer']);
        $o=$this->act($o,'return',['stage'=>'requested','allocation_id'=>$o['allocations'][0]['id'],'quantity'=>1,'reason'=>'Backup evidence']);
        $bytes=$this->postJson('/api/backup/export',['modules'=>array_keys(\App\Services\PortableBackupService::MODULES),'passphrase'=>'outbound-backup-secret'])->assertOk()->getContent();
        $target=Company::factory()->create();$user=User::factory()->create(['company_id'=>$target->id,'role'=>'admin','api_token'=>hash('sha256','restorer'),'email_verified_at'=>now()]);
        $this->withToken('restorer')->post('/api/backup/import',['file'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('outbound.aimsbackup',$bytes),'passphrase'=>'outbound-backup-secret'])->assertOk();
        $restored=SalesOrder::where('company_id',$target->id)->where('order_number',$o['order_number'])->firstOrFail();
        $data=$this->getJson('/api/sales-orders/'.$restored->id)->assertOk()->json();$this->assertEquals('delivered',$data['status']);
        $source=\App\Models\OutboundPackageItem::findOrFail($data['returns'][0]['outbound_package_item_id']);
        $this->assertEquals($data['allocations'][0]['id'],$source->outbound_allocation_id);$this->assertNotEquals($o['allocations'][0]['id'],$source->outbound_allocation_id);
        $this->assertEquals(16,Product::where('company_id',$target->id)->where('sku','OUT-1')->value('quantity'));
    }

    public function test_expected_availability_uses_dated_receipts_and_respects_other_demand():void
    {
        $service=app(\App\Services\InventorySnapshotService::class);
        $date=now()->addDays(5)->toDateString();$later=now()->addDays(10)->toDateString();
        $snapshot=['available'=>1,'committed_outgoing'=>3,'incoming_schedule'=>[['expected_at'=>null,'quantity'=>100],['expected_at'=>now()->subDay()->toDateString(),'quantity'=>100],['expected_at'=>$date,'quantity'=>4],['expected_at'=>$later,'quantity'=>4]]];
        $this->assertSame($later,$service->expectedAvailabilityDate($snapshot,5));
        $this->assertSame($date,$service->expectedAvailabilityDate($snapshot,5,3));
        $this->assertNull($service->expectedAvailabilityDate($snapshot,20));
    }

    public function test_fifo_location_fallback_and_context_do_not_duplicate_demand():void
    {
        [$p,$c]=$this->fixture();$product=Product::findOrFail($p['id']);
        $warehouse=$this->postJson('/api/warehouses',['name'=>'Second','code'=>'SECOND'])->assertCreated()->json();
        app(\App\Services\StockMovementService::class)->store(['product_id'=>$product->id,'warehouse_id'=>$warehouse['id'],'type'=>'in','quantity'=>5,'stock_state'=>'available','reason'=>'Second receipt','movement_code'=>'import_adjustment_in','idempotency_key'=>(string)Str::uuid()]);
        $o=$this->act($this->order($p,$c,22),'confirm');$o=$this->act($o,'reserve');$o=$this->act($o,'allocate');
        $this->assertEquals($product->default_warehouse_id,$o['allocations'][0]['warehouse_id']);$this->assertEquals(20,$o['allocations'][0]['quantity']);
        $this->assertEquals(2,$o['allocations'][1]['quantity']);
        $this->getJson('/api/entity-context/product/'.$product->id)->assertOk()->assertJsonPath('outbound.summary.reserved','22.000');
        $this->getJson('/api/entity-context/warehouse/'.$warehouse['id'])->assertOk()->assertJsonPath('outbound.summary.open_tasks',1);
        $this->act($o,'cancel');Warehouse::findOrFail($warehouse['id'])->update(['is_active'=>false]);
        $new=$this->order($p,$c);$candidates=$this->getJson('/api/sales-order-items/'.$new['items'][0]['id'].'/candidates')->assertOk()->json();
        $this->assertNotContains($warehouse['id'],array_column($candidates,'warehouse_id'));
    }
}
