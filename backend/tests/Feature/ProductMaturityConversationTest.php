<?php
namespace Tests\Feature;

use App\Models\{Product,Category,Company,Supplier,PurchaseOrder,Shipment,Warehouse};
use App\Services\{AssistantPlanner,AssistantEntityResolver,ShipmentCargoPresentation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMaturityConversationTest extends TestCase
{
    use RefreshDatabase;
    private function login():void{$this->actingAsApiUser('admin');$this->getJson('/api/me')->assertOk();config(['assistant.provider'=>'deterministic']);}
    private function product(string $name,string $sku,string $unit='pcs'):Product{return Product::create(['name'=>$name,'sku'=>$sku,'unit'=>$unit,'quantity'=>20,'min_quantity'=>5,'price'=>3,'purchase_price'=>2,'category_id'=>Category::firstOrCreate(['name'=>'PM4'])->id]);}
    public function test_short_typo_and_mixed_language_questions_route_locally():void
    {
        foreach([
            'whats stock risk milano 04'=>'stockout','milano 04 when will finish'=>'stockout',
            'which suplier can cover'=>'compare','which supplier better for milano'=>'compare',
            'what i need to order'=>'replenishment','qka duhet me porosit'=>'replenishment',
            'cilat produkte kan me mbet pa stok'=>'stockout','cilat produkte kan me met pa stock'=>'stockout',
            'show costumers owe me most'=>'debt','show costumers with most borxh'=>'debt','which customer ka borxhin ma te madh'=>'debt',
            'where is shipemnt from china'=>'shipments','ku osht porosia prej kines'=>'shipments','shipment china when arrive'=>'shipments',
            'show me porosite qe jane late'=>'orders','cilat shipments are at risk'=>'shipments',
            'what happens if ship late 10 days'=>'scenario','sa stock kemi per Milano 04'=>'record',
            'Cili furnitor mund ta mbulojë?'=>'compare','Po nëse dërgesa vonohet 10 ditë?'=>'scenario',
            'Parashikimi i parasë për 30 ditë'=>'cash',
            'show sales order SO-2026-000308'=>'orders','show purchase order PO-2026-0048'=>'orders',
        ] as $question=>$intent)$this->assertSame($intent,app(AssistantPlanner::class)->plan($question)['intent'],$question);
        $this->assertSame('milnao 04',app(AssistantPlanner::class)->plan('milnao 04 status')['term']);
        $this->assertNull(app(AssistantPlanner::class)->plan('Cili furnitor mund ta mbulojë?')['term']);
        $this->assertNull(app(AssistantPlanner::class)->plan('Po nëse dërgesa vonohet 10 ditë?')['term']);
        $this->assertSame('PO-2026-0048',app(AssistantPlanner::class)->plan('show purchase order PO-2026-0048')['term']);
        $this->assertSame('sales_order',app(AssistantPlanner::class)->plan('show sales order SO-2026-000308')['type']);
    }
    public function test_fuzzy_names_and_compact_codes_resolve_but_variants_need_selection():void
    {
        $this->login();$p=$this->product('Milano 04','MIL-04');$this->product('Milano 01','MIL-01');$this->product('Milano 02','MIL-02');
        $this->assertSame($p->id,app(AssistantEntityResolver::class)->resolve('milano4','product')['entity']['id']);
        $this->assertSame($p->id,app(AssistantEntityResolver::class)->resolve('milnao 04','product')['entity']['id']);
        $r=$this->postJson('/api/intelligence-assistant/ask',['question'=>'milnao 04 status'])->assertOk()->assertJsonPath('context.id',$p->id)->json();
        $this->assertStringContainsString('Milano 04',$r['resolution']);
        $this->postJson('/api/intelligence-assistant/ask',['question'=>'show Milano'])->assertOk()->assertJsonCount(3,'choices')->assertJsonCount(0,'cards');
    }
    public function test_visible_starter_questions_are_business_queries_not_entity_names():void
    {
        $this->login();
        foreach([
            ['What needs my attention?','brief','en'],['Çfarë kërkon vëmendjen time?','brief','sq'],
            ['Which products may run out?','stockout','en'],['Cilat produkte mund të mbarojnë?','stockout','sq'],
            ['Where is my incoming stock?','shipments','en'],['Ku është stoku që presim?','shipments','sq'],
            ['Who owes us the most?','debt','en'],['Kush na ka më shumë borxh?','debt','sq'],
        ] as [$question,$intent,$language]) {
            $plan=app(AssistantPlanner::class)->plan($question);
            $this->assertSame($intent,$plan['intent'],$question);
            $this->assertNull($plan['term'],$question);
            $reply=$this->postJson('/api/intelligence-assistant/ask',['question'=>$question,'language'=>$language])->assertOk()->json();
            $this->assertSame($intent,$reply['intent'],$question);
            $this->assertNotEmpty($reply['sources'],$question);
        }
    }
    public function test_entity_choices_and_context_are_tenant_scoped():void
    {
        $this->login();$company=Company::factory()->create();
        Product::withoutEvents(fn()=>Product::create(['company_id'=>$company->id,'name'=>'Milano 04','sku'=>'MIL-04','unit'=>'pcs','quantity'=>500,'price'=>3]));
        $this->postJson('/api/intelligence-assistant/ask',['question'=>'milnao 04 status'])->assertOk()->assertJsonCount(0,'choices')->assertJsonCount(0,'cards');
    }
    public function test_followups_keep_product_and_choose_its_only_incoming_shipment():void
    {
        $this->login();$p=$this->product('Milano 04','MIL-04');$supplier=Supplier::create(['name'=>'China Factory','is_active'=>true]);
        \App\Models\ProductSupplier::create(['product_id'=>$p->id,'supplier_id'=>$supplier->id,'purchase_price'=>2,'currency'=>'EUR','usual_lead_time_days'=>5,'is_active'=>true]);
        $po=PurchaseOrder::create(['supplier_id'=>$supplier->id,'po_number'=>'PO-PM4','status'=>'ordered','ordered_at'=>today(),'expected_at'=>today()->addDays(10),'currency'=>'EUR','total_amount'=>200]);
        $i=$po->items()->create(['product_id'=>$p->id,'description'=>$p->name,'unit'=>'pcs','quantity'=>100,'base_quantity'=>100,'inventory_unit'=>'pcs','conversion_factor'=>1,'unit_price'=>2,'line_total'=>200]);
        $s=Shipment::create(['tracking_number'=>'SH-PM4','vessel_name'=>'Test Vessel','purchase_order_id'=>$po->id,'transport_mode'=>'sea','status'=>'in_transit','origin_port'=>'Shanghai China','destination_port'=>'Durres','eta'=>today()->addDays(10)]);
        $s->items()->create(['purchase_order_item_id'=>$i->id,'product_id'=>$p->id,'description'=>$p->name,'unit'=>'pcs','quantity'=>100,'planned_quantity'=>100,'base_quantity'=>100]);
        app(\App\Services\ShipmentIntelligenceService::class)->refresh($s->id);
        $first=$this->postJson('/api/intelligence-assistant/ask',['question'=>'whats stock risk milano 04'])->assertOk()->json();
        $conversation=['conversation_id'=>$first['conversation_id']];
        $this->postJson('/api/intelligence-assistant/ask',$conversation+['question'=>'why first one'])->assertOk()->assertJsonPath('context.id',$p->id);
        $this->postJson('/api/intelligence-assistant/ask',$conversation+['question'=>'which suplier can cover'])->assertOk()->assertJsonPath('sources.0.tool','get_supplier_options');
        $delay=$this->postJson('/api/intelligence-assistant/ask',$conversation+['question'=>'what if shipment 10 days late'])->assertOk()->json();
        $this->assertContains('simulate_shipment_delay',array_column($delay['sources'],'tool'));
        $this->assertCount(2,$delay['context_entities']);
        $this->postJson('/api/intelligence-assistant/ask',$conversation+['question'=>'how much i need order'])->assertOk()->assertJsonPath('context.id',$p->id)->assertJsonPath('sources.1.tool','get_inventory_plan');
        $this->assertSame(20.0,(float)$p->fresh()->quantity);$this->assertDatabaseCount('purchase_orders',1);
    }
    public function test_cargo_groups_units_and_does_not_invent_allocation_or_receipt_attribution():void
    {
        $this->login();$p=$this->product('Fabric','FAB','m');$h=$this->product('Hardware','HW');$w=Warehouse::create(['name'=>'Ferizaj Main','code'=>'FER','is_active'=>true]);$supplier=Supplier::create(['name'=>'China Factory']);
        $po=PurchaseOrder::create(['supplier_id'=>$supplier->id,'warehouse_id'=>$w->id,'po_number'=>'PO-PM4-CARGO','currency'=>'EUR','status'=>'ordered','total_amount'=>300]);
        $a=$po->items()->create(['product_id'=>$p->id,'description'=>'Fabric','unit'=>'m','quantity'=>100,'received_quantity'=>10,'unit_price'=>2,'line_total'=>200]);
        $b=$po->items()->create(['product_id'=>$h->id,'description'=>'Hardware','unit'=>'pcs','quantity'=>50,'received_quantity'=>0,'unit_price'=>2,'line_total'=>100]);
        $s=Shipment::create(['tracking_number'=>'SH-CARGO','purchase_order_id'=>$po->id,'warehouse_id'=>$w->id,'status'=>'in_transit']);
        $empty=app(ShipmentCargoPresentation::class)->forShipment($s);
        $this->assertFalse($empty['allocation_known']);$this->assertNull($empty['groups'][0]['items'][0]['allocated']);$this->assertSame(2,$empty['product_count']);
        $s->items()->create(['purchase_order_item_id'=>$a->id,'product_id'=>$p->id,'description'=>'Fabric','unit'=>'m','quantity'=>60,'planned_quantity'=>60,'base_quantity'=>60]);
        $s->items()->create(['purchase_order_item_id'=>$b->id,'product_id'=>$h->id,'description'=>'Hardware','unit'=>'pcs','quantity'=>20,'planned_quantity'=>20,'base_quantity'=>20]);
        $payload=$this->getJson('/api/shipments/'.$s->id)->assertOk()->json('cargo');
        $this->assertSame([['quantity'=>60,'unit'=>'m'],['quantity'=>20,'unit'=>'pcs']],$payload['quantities']);
        $this->assertSame('purchase_order',$payload['receipts_scope']);$this->assertSame('Ferizaj Main',$payload['groups'][0]['destination']);
        $this->assertNull($payload['groups'][0]['items'][0]['loaded']);
        $other=Shipment::create(['tracking_number'=>'SH-SPLIT','purchase_order_id'=>$po->id]);
        $other->items()->create(['purchase_order_item_id'=>$a->id,'product_id'=>$p->id,'description'=>'Fabric','unit'=>'m','quantity'=>20,'planned_quantity'=>20,'base_quantity'=>20]);
        $updated=$this->putJson('/api/shipments/'.$s->id.'/logistics',['notes'=>'Updated cargo review'])->assertOk()->json('cargo');
        $this->assertSame(20,$updated['groups'][0]['items'][0]['unallocated']);
        $this->assertSame('SH-SPLIT',$updated['groups'][0]['items'][0]['other_shipments'][0]['reference']);
        $s->items()->create(['purchase_order_item_id'=>$a->id,'product_id'=>$p->id,'description'=>'Fabric','unit'=>'roll','quantity'=>1,'planned_quantity'=>1,'base_quantity'=>10]);
        $mixed=app(ShipmentCargoPresentation::class)->forShipment($s);
        $this->assertNull($mixed['groups'][0]['items'][0]['allocated']);
        $this->assertNull($mixed['groups'][0]['items'][0]['unallocated']);
        $this->assertSame([['unit'=>'m','quantity'=>60.0],['unit'=>'roll','quantity'=>1.0]],$mixed['groups'][0]['items'][0]['allocation_quantities']);
    }
}
