<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Models\{Category,Customer,Product,SalesOrder,OrderIntake,OrderChannel,OrderChannelKey,Company};
use App\Services\OrderHubService;

class OrderHubTest extends TestCase {
    use RefreshDatabase;
    private function fixture(array $options=[]):array {
        $this->actingAsApiUser();
        $category=Category::create($this->tenantAttributes(['name'=>'Hub products']));
        $warehouse=$this->postJson('/api/warehouses',['name'=>'Hub warehouse','code'=>'HUB','is_default'=>true])->assertCreated()->json();
        $product=$this->postJson('/api/products',['name'=>'Hub item','sku'=>'HUB-1','category_id'=>$category->id,'default_warehouse_id'=>$warehouse['id'],'unit'=>'pcs','quantity'=>10,'min_quantity'=>1,'purchase_price'=>2,'selling_price'=>5,'price'=>5])->assertCreated()->json();
        $customer=Customer::create($this->tenantAttributes(['name'=>'Buyer','email'=>'buyer@example.test','current_debt'=>0,'current_credit'=>0,'is_active'=>true]));
        $channel=OrderChannel::create($this->tenantAttributes(['name'=>'Website','type'=>'api','enabled'=>true,'currency'=>'EUR','oversale_policy'=>'accept_backorder',...$options]));
        return [$product,$customer,$channel];
    }
    private function payload($customer,array $extra=[]):array {return ['customer_id'=>$customer->id,'external_id'=>'WEB-1','idempotency_key'=>'request-1','order_date'=>today()->toDateString(),'payment_type'=>'cash','items'=>[['sku'=>'HUB-1','quantity'=>3,'unit_price'=>'5.00']],...$extra];}
    private function submit($channel,$payload){return $this->postJson('/api/order-hub/channels/'.$channel->id.'/orders',$payload);}
    public function test_intake_normalizes_matches_reserves_and_retries_without_duplicate_business_effects():void {
        [$p,$c,$ch]=$this->fixture();$d=$this->payload($c);$i=$this->submit($ch,$d)->assertCreated()->assertJsonPath('state','validated')->json();
        $this->submit($ch,[...$d,'idempotency_key'=>'another-key'])->assertCreated()->assertJsonPath('id',$i['id']);
        $this->assertDatabaseCount('sales_orders',1);$this->assertDatabaseCount('order_intakes',1);
        $path='/api/order-hub/intakes/'.$i['id'].'/confirm';
        $this->postJson($path,['idempotency_key'=>'confirm'])->assertOk()->assertJsonPath('order.items.0.reserved_quantity',fn($v)=>(string)(float)$v==='3');
        $this->postJson($path,['idempotency_key'=>'confirm'])->assertOk();$this->assertDatabaseCount('outbound_allocations',1);$this->assertEquals(10,Product::find($p['id'])->quantity);
        $this->postJson('/api/order-hub/intakes/'.$i['id'].'/cancel',['idempotency_key'=>'cancel'])->assertOk();$this->assertEquals(10,Product::find($p['id'])->available_quantity);
    }
    public function test_unknown_products_wait_for_mapping_and_can_be_reprocessed():void {
        [$p,$c,$ch]=$this->fixture();$i=$this->submit($ch,$this->payload($c,['items'=>[['external_product_id'=>'remote-4','quantity'=>2]]]))->assertCreated()->assertJsonPath('state','attention')->json();
        $this->assertDatabaseCount('products',1);$this->assertDatabaseCount('sales_orders',0);
        $this->postJson('/api/order-hub/channels/'.$ch->id.'/mappings',['kind'=>'product','external_id'=>'remote-4','entity_id'=>$p['id']])->assertOk();
        $this->postJson('/api/order-hub/intakes/'.$i['id'].'/retry',['idempotency_key'=>'retry'])->assertOk()->assertJsonPath('state','validated');
    }
    public function test_price_review_cannot_be_bypassed_through_the_existing_sales_api():void {
        [$p,$c,$ch]=$this->fixture();$i=$this->submit($ch,$this->payload($c,['items'=>[['sku'=>'HUB-1','quantity'=>2,'unit_price'=>1]]]))->assertCreated()->assertJsonPath('state','attention')->json();
        $this->postJson('/api/sales-orders/'.$i['sales_order_id'].'/confirm',['idempotency_key'=>'bypass'])->assertUnprocessable();
        $this->putJson('/api/order-hub/intakes/'.$i['id'],[...$i['payload'],'review_reason'=>'Contract discount verified'])->assertOk()->assertJsonPath('state','validated');
        $this->postJson('/api/order-hub/intakes/'.$i['id'].'/confirm',['idempotency_key'=>'approved-price'])->assertOk();
    }
    public function test_external_mutation_is_a_conflict_not_a_new_order():void {
        [$p,$c,$ch]=$this->fixture();$d=$this->payload($c);$this->submit($ch,$d)->assertCreated();$d['items'][0]['quantity']=4;
        $this->submit($ch,$d)->assertUnprocessable();$this->assertDatabaseCount('sales_orders',1);
    }
    public function test_blocked_credit_and_hard_stock_policy_are_enforced():void {
        [$p,$c,$ch]=$this->fixture(['oversale_policy'=>'do_not_accept']);
        $i=$this->submit($ch,$this->payload($c,['items'=>[['sku'=>'HUB-1','quantity'=>20]]]))->assertCreated()->json();
        $this->postJson('/api/order-hub/intakes/'.$i['id'].'/confirm',['idempotency_key'=>'short'])->assertUnprocessable();
        $c->update(['credit_status'=>'blocked']);
        $i=$this->submit($ch,$this->payload($c,['external_id'=>'WEB-2','idempotency_key'=>'two','payment_type'=>'credit']))->assertCreated()->json();
        $this->postJson('/api/order-hub/intakes/'.$i['id'].'/confirm',['idempotency_key'=>'blocked'])->assertUnprocessable();
        $this->getJson('/api/order-hub/intakes/'.$i['id'])->assertOk()->assertJsonPath('state','attention')->assertJsonPath('states.health','attention');
        $this->assertDatabaseCount('outbound_allocations',0);
    }
    public function test_guest_orders_do_not_create_customers_and_tracking_is_sanitized():void {
        [$p,$c,$ch]=$this->fixture(['allow_guest'=>true]);
        $d=$this->payload($c,['customer_id'=>null,'customer'=>['name'=>'Guest Buyer'],'notes'=>'internal-sensitive']);
        $i=$this->submit($ch,$d)->assertCreated()->assertJsonPath('state','validated')->json();$this->assertDatabaseCount('customers',1);
        $this->postJson('/api/order-hub/intakes/'.$i['id'].'/confirm',['idempotency_key'=>'guest'])->assertOk();
        $token=$this->postJson('/api/order-hub/intakes/'.$i['id'].'/tracking-link',['idempotency_key'=>'link'])->assertOk()->json('token');
        $this->getJson('/api/order-api/v1/tracking/'.$token)->assertOk()->assertJsonMissingPath('notes')->assertJsonMissingPath('credit')->assertJsonMissingPath('payload');
    }
    public function test_channel_api_scopes_tenant_isolation_revocation_and_signature():void {
        [$p,$c,$ch]=$this->fixture();
        $key=$this->postJson('/api/order-hub/channels/'.$ch->id.'/keys',['scopes'=>['orders:create'],'expires_at'=>now()->addDay()->toIso8601String()])->assertCreated()->json();
        $this->assertNotEquals($key['token'],OrderChannelKey::first()->token_hash);
        $this->withHeader('Authorization','Bearer '.$key['token'])->getJson('/api/order-api/v1/catalog')->assertForbidden();
        $this->withHeader('Authorization','Bearer '.$key['token'])->postJson('/api/order-api/v1/orders',$this->payload($c))->assertCreated();
        $this->withHeader('Authorization','Bearer '.$key['token'])->postJson('/api/order-api/v1/webhook',$this->payload($c))->assertUnauthorized();
        OrderChannelKey::first()->update(['revoked_at'=>now()]);
        $this->withHeader('Authorization','Bearer '.$key['token'])->postJson('/api/order-api/v1/orders',$this->payload($c))->assertUnauthorized();
    }
    public function test_validated_external_order_cannot_be_changed_through_legacy_edit_then_confirmed():void {
        [$p,$c,$ch]=$this->fixture();$i=$this->submit($ch,$this->payload($c))->assertCreated()->json();
        $this->postJson('/api/sales-orders/'.$i['sales_order_id'].'/edit',['customer_id'=>$c->id,'order_date'=>today()->toDateString(),'payment_type'=>'cash','items'=>[['product_id'=>$p['id'],'quantity'=>3,'unit_price'=>1]],'idempotency_key'=>'legacy-change'])->assertOk();
        $this->postJson('/api/sales-orders/'.$i['sales_order_id'].'/confirm',['idempotency_key'=>'legacy-confirm'])->assertUnprocessable()->assertJsonFragment(['The order changed after validation. Review it again in Order Hub before confirming.']);
        $this->postJson('/api/order-hub/intakes/'.$i['id'].'/retry',['idempotency_key'=>'refresh'])->assertOk();
        $this->postJson('/api/order-hub/intakes/'.$i['id'].'/confirm',['idempotency_key'=>'valid-confirm'])->assertOk();
    }
    public function test_backorder_review_is_explicit_and_invalidated_by_order_edit():void {
        [$p,$c,$ch]=$this->fixture(['oversale_policy'=>'require_review']);$payload=$this->payload($c,['items'=>[['sku'=>'HUB-1','quantity'=>20]]]);$i=$this->submit($ch,$payload)->assertCreated()->json();
        $path='/api/order-hub/intakes/'.$i['id'];
        $this->postJson($path.'/confirm',['idempotency_key'=>'blocked'])->assertUnprocessable();
        $this->postJson($path.'/review-stock',['idempotency_key'=>'review','reason'=>'Buyer agrees to wait for replenishment'])->assertOk();
        $payload['items'][0]['quantity']=21;
        $this->putJson($path,$payload)->assertOk();
        $this->postJson($path.'/confirm',['idempotency_key'=>'edited-blocked'])->assertUnprocessable();
        $this->postJson($path.'/review-stock',['idempotency_key'=>'review-new','reason'=>'New quantity verified with buyer'])->assertOk();
        $this->postJson($path.'/confirm',['idempotency_key'=>'accepted'])->assertOk()->assertJsonPath('states.backordered',true);
        $this->assertEquals(10,Product::find($p['id'])->quantity);
    }
    public function test_original_payload_and_sync_conflict_are_preserved():void {
        [$p,$c,$ch]=$this->fixture();$original=$this->payload($c);$i=$this->submit($ch,$original)->assertCreated()->json();
        $changed=$original;$changed['items'][0]['quantity']=4;
        $this->submit($ch,$changed)->assertUnprocessable();
        $record=OrderIntake::find($i['id']);$this->assertSame(1,$record->conflict_count);$this->assertSame(3,$record->received_payload['items'][0]['quantity']);
        $this->putJson('/api/order-hub/intakes/'.$i['id'],$changed)->assertOk();
        $this->assertSame(3,$record->fresh()->received_payload['items'][0]['quantity']);
    }
    public function test_customer_portal_is_bound_to_one_customer_and_cannot_read_other_orders():void {
        [$p,$c,$ch]=$this->fixture();$other=Customer::create($this->tenantAttributes(['name'=>'Other buyer','is_active'=>true]));
        $otherOrder=$this->submit($ch,$this->payload($other))->assertCreated()->json();
        $key=$this->postJson('/api/order-hub/channels/'.$ch->id.'/keys',['scopes'=>['orders:create','orders:read','catalog:read'],'customer_id'=>$c->id,'expires_at'=>now()->addDay()->toIso8601String()])->assertCreated()->json();
        $this->withHeader('Authorization','Bearer '.$key['token']);
        $this->getJson('/api/order-api/v1/orders/'.$otherOrder['id'])->assertNotFound();
        $this->postJson('/api/order-api/v1/orders',$this->payload($other,['external_id'=>'OWN-2','idempotency_key'=>'OWN-2']))->assertCreated();
        $this->getJson('/api/order-api/v1/portal')->assertOk()->assertJsonPath('customer.id',$c->id)->assertJsonCount(1,'orders.data')->assertJsonMissingPath('orders.data.0.payload');
        $this->assertEquals($c->id,SalesOrder::latest('id')->first()->customer_id);
        $pending=$this->postJson('/api/order-api/v1/orders',$this->payload($c,['external_id'=>'UNMATCHED','idempotency_key'=>'UNMATCHED','items'=>[['sku'=>'MISSING','quantity'=>1]]]))->assertCreated()->json('id');
        $this->getJson('/api/order-api/v1/orders/'.$pending)->assertOk()->assertJsonPath('states.fulfillment','under_review');
    }
    public function test_signed_webhook_replay_is_idempotent_and_old_signature_expires():void {
        [$p,$c,$ch]=$this->fixture();$key=$this->postJson('/api/order-hub/channels/'.$ch->id.'/keys',['scopes'=>['orders:create'],'expires_at'=>now()->addDay()->toIso8601String()])->assertCreated()->json();
        $payload=$this->payload($c);$timestamp=(string)time();$body=json_encode($payload);
        $headers=['Authorization'=>'Bearer '.$key['token'],'X-AIMS-Timestamp'=>$timestamp,'X-AIMS-Signature'=>hash_hmac('sha256',$timestamp.'.'.$body,$key['webhook_secret'])];
        $first=$this->postJson('/api/order-api/v1/webhook',$payload,$headers)->assertCreated()->json('id');
        $this->postJson('/api/order-api/v1/webhook',$payload,$headers)->assertCreated()->assertJsonPath('id',$first);
        $this->postJson('/api/order-api/v1/webhook',$payload,[...$headers,'X-AIMS-Timestamp'=>(string)(time()-600)])->assertUnauthorized();
        $this->assertDatabaseCount('sales_orders',1);
    }
    public function test_csv_preview_has_no_effect_and_import_replay_does_not_duplicate():void {
        [$p,$c,$ch]=$this->fixture();$file=\Illuminate\Http\UploadedFile::fake()->createWithContent('orders.csv',"external_id,customer_email,sku,quantity\nCSV-1,buyer@example.test,HUB-1,2\n");
        $preview=$this->post('/api/order-hub/imports/preview',['file'=>$file])->assertOk()->json();$this->assertDatabaseCount('sales_orders',0);
        $this->postJson('/api/order-hub/channels/'.$ch->id.'/imports',['orders'=>$preview['orders']])->assertOk();
        $this->postJson('/api/order-hub/channels/'.$ch->id.'/imports',['orders'=>$preview['orders']])->assertOk();$this->assertDatabaseCount('sales_orders',1);
    }
    public function test_channel_and_product_cross_tenant_requests_are_denied_without_side_effects():void {
        [$p,$c,$ch]=$this->fixture();$company=$this->apiCompany;
        $this->actingAsApiUser('manager');
        $this->getJson('/api/order-hub/channels/'.$ch->id.'/mappings')->assertNotFound();
        $this->submit($ch,$this->payload($c))->assertNotFound();
        $own=OrderChannel::create($this->tenantAttributes(['name'=>'Own channel','type'=>'api','enabled'=>true,'currency'=>'EUR','allow_guest'=>true]));
        $this->submit($own,['idempotency_key'=>'foreign-product','customer'=>['name'=>'Guest'],'payment_type'=>'cash','order_date'=>today()->toDateString(),'items'=>[['product_id'=>$p['id'],'quantity'=>1]]])->assertCreated()->assertJsonPath('state','attention');
        $this->assertDatabaseCount('sales_orders',0);
    }
    public function test_order_acceptance_uses_existing_approval_engine_and_exact_signature():void {
        [$p,$c,$ch]=$this->fixture();\App\Models\ApprovalRule::create($this->tenantAttributes(['rule_type'=>'order_acceptance','threshold_amount'=>10,'is_active'=>true,'separation_of_duties'=>true]));
        $i=$this->submit($ch,$this->payload($c))->assertCreated()->json();$path='/api/order-hub/intakes/'.$i['id'];
        $this->postJson($path.'/confirm',['idempotency_key'=>'blocked-rule'])->assertUnprocessable();
        $a=$this->postJson($path.'/request-approval',['idempotency_key'=>'request-approval','reason'=>'Customer requested this order'])->assertOk()->json('acceptance_approval');
        $this->postJson($path.'/request-approval',['idempotency_key'=>'retry-approval','reason'=>'Customer requested this order'])->assertOk()->assertJsonPath('acceptance_approval.id',$a['id']);
        $this->postJson('/api/approvals/'.$a['id'].'/decision',['decision'=>'approved'])->assertUnprocessable();
        \App\Models\User::factory()->create(['company_id'=>$this->apiCompany->id,'role'=>'manager','api_token'=>hash('sha256','hub-manager'),'email_verified_at'=>now()]);
        $this->withHeader('Authorization','Bearer hub-manager')->postJson('/api/approvals/'.$a['id'].'/decision',['decision'=>'approved'])->assertOk();
        $this->postJson($path.'/confirm',['idempotency_key'=>'approved-rule'])->assertOk();
    }
    public function test_promotion_prices_are_fixed_precision_and_historical():void {
        [$p,$c,$ch]=$this->fixture();$ch->update(['configuration'=>['price_rules'=>[['name'=>'Volume discount','enabled'=>true,'kind'=>'percentage','value'=>'10.00','minimum_quantity'=>'3','product_id'=>$p['id']]]]]);
        $i=$this->submit($ch,$this->payload($c,['items'=>[['sku'=>'HUB-1','quantity'=>3]]]))->assertCreated()->json();
        $this->assertEquals(13.50,$i['order']['total_amount']);$this->assertSame('4.50',$i['order']['metadata']['pricing_snapshot'][0]['expected_price']);
        $this->postJson('/api/order-hub/intakes/'.$i['id'].'/confirm',['idempotency_key'=>'discount-confirm'])->assertOk();
        Product::find($p['id'])->update(['selling_price'=>20]);
        $this->getJson('/api/order-hub/intakes/'.$i['id'])->assertOk()->assertJsonPath('order.metadata.pricing_snapshot.0.accepted_price','4.50');
    }
    public function test_order_exports_and_batch_actions_use_real_scoped_orders():void {
        [$p,$c,$ch]=$this->fixture();$i=$this->submit($ch,$this->payload($c))->assertCreated()->json();
        $this->getJson('/api/order-hub/export?format=csv')->assertOk()->assertHeader('Content-Type','text/csv; charset=UTF-8');
        $xlsx=$this->getJson('/api/order-hub/export?format=xlsx')->assertOk()->getContent();$this->assertStringStartsWith('PK',$xlsx);
        $pdf=$this->getJson('/api/order-hub/export?format=pdf&language=sq')->assertOk()->getContent();$this->assertStringStartsWith('%PDF',$pdf);
        $this->postJson('/api/order-hub/bulk',['ids'=>[$i['id']],'action'=>'confirm','idempotency_key'=>'batch'])->assertOk()->assertJsonPath('results.0.success',true);
        $this->postJson('/api/order-hub/bulk',['ids'=>[$i['id']],'action'=>'confirm','idempotency_key'=>'batch'])->assertOk();$this->assertDatabaseCount('outbound_allocations',1);
    }
    public function test_encrypted_hub_backup_restores_relationships_without_portable_credentials():void {
        [$p,$c,$ch]=$this->fixture();$i=$this->submit($ch,$this->payload($c,['items'=>[['product_id'=>$p['id'],'quantity'=>2]]]))->assertCreated()->json();
        $this->postJson('/api/order-hub/intakes/'.$i['id'].'/tracking-link',['idempotency_key'=>'link'])->assertOk();
        $this->postJson('/api/order-hub/channels/'.$ch->id.'/keys',['scopes'=>['orders:read'],'expires_at'=>now()->addDay()->toIso8601String()])->assertCreated();
        $backup=$this->postJson('/api/backup/export',['modules'=>['order_hub'],'passphrase'=>'order-hub-backup-passphrase'])->assertOk()->getContent();
        $target=Company::factory()->create();\App\Models\User::factory()->create(['company_id'=>$target->id,'role'=>'admin','api_token'=>hash('sha256','hub-restore'),'email_verified_at'=>now()]);
        $this->withHeader('Authorization','Bearer hub-restore');
        $file=\Illuminate\Http\UploadedFile::fake()->createWithContent('hub.aimsbackup',$backup);
        $this->post('/api/backup/import',['file'=>$file,'passphrase'=>'order-hub-backup-passphrase','mode'=>'merge'])->assertOk();
        $restored=OrderIntake::firstOrFail();$this->assertSame($target->id,$restored->company_id);$this->assertNull($restored->tracking_hash);
        $this->assertSame($restored->order->customer_id,$restored->payload['customer_id']);$this->assertSame($restored->order->items->first()->product_id,$restored->payload['items'][0]['product_id']);
        $this->assertSame(0,OrderChannelKey::where('company_id',$target->id)->count());
    }
    public function test_guest_dispatch_delivery_and_cash_refund_do_not_create_customer_ledger():void {
        [$p,$c,$ch]=$this->fixture(['allow_guest'=>true]);$i=$this->submit($ch,$this->payload($c,['customer_id'=>null,'customer'=>['name'=>'Cash guest']]))->assertCreated()->json();
        $o=$this->postJson('/api/order-hub/intakes/'.$i['id'].'/confirm',['idempotency_key'=>'guest-flow'])->assertOk()->json('order');
        $act=function($name,$data=[])use(&$o){$o=$this->postJson('/api/sales-orders/'.$o['id'].'/'.$name,['idempotency_key'=>(string)Str::uuid(),...$data])->assertOk()->json();};
        $act('allocate');$a=$o['allocations'][0];$act('pick',['allocation_id'=>$a['id'],'quantity'=>3,'manual_verification'=>true]);$act('pack',['items'=>[['allocation_id'=>$a['id'],'quantity'=>3]]]);$act('dispatch',['package_ids'=>[$o['packages'][0]['id']]]);$act('delivery',['dispatch_id'=>$o['dispatches'][0]['id'],'recipient'=>'Cash guest']);
        $act('return',['stage'=>'requested','allocation_id'=>$a['id'],'quantity'=>1,'reason'=>'Package damaged']);$return=$o['returns'][0]['id'];$act('return',['stage'=>'authorized','return_id'=>$return]);$act('return',['stage'=>'received','return_id'=>$return]);
        $account=$this->postJson('/api/finance/accounts',['name'=>'Guest refunds','type'=>'cashbox','currency'=>'EUR','opening_balance'=>100,'opening_date'=>today()->toDateString()])->assertCreated()->json();
        $act('return',['stage'=>'resolved','return_id'=>$return,'disposition'=>'quarantine','financial_account_id'=>$account['id']]);
        $this->assertDatabaseCount('customers',1);$this->assertDatabaseCount('customer_debt_transactions',0);$this->assertEquals(8,Product::find($p['id'])->quantity);
        $this->assertDatabaseHas('financial_account_transactions',['financial_account_id'=>$account['id'],'type'=>'refund_out','amount'=>5]);
        $this->getJson('/api/order-hub/intakes/'.$i['id'])->assertOk()->assertJsonPath('states.payment','partially_refunded');
    }
    public function test_availability_scope_does_not_expose_prices_or_private_product_data():void {
        [$p,$c,$ch]=$this->fixture();$key=$this->postJson('/api/order-hub/channels/'.$ch->id.'/keys',['scopes'=>['availability:read'],'expires_at'=>now()->addDay()->toIso8601String()])->assertCreated()->json();
        $this->withHeader('Authorization','Bearer '.$key['token'])->getJson('/api/order-api/v1/availability')->assertOk()->assertJsonPath('data.0.id',$p['id'])->assertJsonMissingPath('data.0.price')->assertJsonMissingPath('data.0.purchase_price');
        $this->getJson('/api/order-api/v1/catalog')->assertForbidden();
    }
}
