<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\QualityInspection;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QualityManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_receipt_is_quarantined_and_partial_disposition_reconciles_inventory(): void
    {
        [$product,$supplier,$warehouse,$order] = $this->purchasingFixture('required', 10, now()->addDays(3)->toDateString());

        $response=$this->postJson("/api/purchase-orders/{$order['id']}/receive",[
            'warehouse_id'=>$warehouse['id'],'items'=>[['id'=>$order['items'][0]['id'],'accepted_quantity'=>10]],
            'idempotency_key'=>(string)Str::uuid(),
        ])->assertOk();
        $inspection=QualityInspection::firstOrFail();
        $this->assertSame('PENDING',$inspection->status);
        $balance=WarehouseStock::where('product_id',$product->id)->firstOrFail();
        $this->assertSame(0.0,(float)$balance->available_quantity);
        $this->assertSame(10.0,(float)$balance->quarantine_quantity);

        $this->postJson("/api/quality/inspections/{$inspection->id}/finalize",[
            'accepted_quantity'=>6,'rejected_quantity'=>1,'quarantine_quantity'=>2,'damaged_quantity'=>1,'notes'=>'Sample and visual checks completed.',
        ])->assertOk()->assertJsonPath('status','PARTIAL');
        $balance->refresh();
        $this->assertSame(6.0,(float)$balance->available_quantity);
        $this->assertSame(3.0,(float)$balance->quarantine_quantity);
        $this->assertSame(1.0,(float)$balance->damaged_quantity);
        $this->assertSame(10.0,(float)$balance->quantity);
        $this->assertSame(10.0,(float)$product->fresh()->quantity);
        $this->assertDatabaseHas('stock_movements',['source_type'=>'quality_inspection','source_id'=>$inspection->id,'stock_state'=>'available']);
        $this->assertDatabaseHas('business_events',['event_type'=>'quality.inspection_finalized','entity_id'=>$inspection->id]);
    }

    public function test_optional_inspection_stages_existing_receipt_and_pass_releases_it(): void
    {
        [$product,,$warehouse,$order]=$this->purchasingFixture('optional',5);
        $po=$this->postJson("/api/purchase-orders/{$order['id']}/receive",[
            'warehouse_id'=>$warehouse['id'],'items'=>[['id'=>$order['items'][0]['id'],'accepted_quantity'=>5]],'idempotency_key'=>(string)Str::uuid(),
        ])->assertOk()->json();
        $this->assertDatabaseCount('quality_inspections',0);
        $receiptItem=$po['goods_receipts'][0]['items'][0] ?? \App\Models\GoodsReceiptItem::firstOrFail()->toArray();
        $inspection=$this->postJson('/api/quality/inspections',['goods_receipt_item_id'=>$receiptItem['id'],'inspection_scope'=>'sample','inspected_quantity'=>2])
            ->assertCreated()->assertJsonPath('inspection_scope','sample')->json();
        $this->assertSame(5.0,(float)WarehouseStock::where('product_id',$product->id)->value('quarantine_quantity'));
        $this->postJson("/api/quality/inspections/{$inspection['id']}/finalize",[
            'accepted_quantity'=>5,'rejected_quantity'=>0,'quarantine_quantity'=>0,'damaged_quantity'=>0,
        ])->assertOk()->assertJsonPath('status','PASSED');
        $this->assertSame(5.0,(float)WarehouseStock::where('product_id',$product->id)->value('available_quantity'));
    }

    public function test_checklist_defect_claim_and_correction_preserve_finalized_history(): void
    {
        [$product,$supplier,$warehouse,$order]=$this->purchasingFixture('required',4);
        $template=$this->postJson('/api/quality/templates',[
            'name'=>'Dimensional receipt check','items'=>[
                ['name'=>'Width','check_type'=>'numeric','minimum_value'=>9.5,'maximum_value'=>10.5,'is_required'=>true],
                ['name'=>'Packaging','check_type'=>'pass_fail','is_required'=>true],
            ],
        ])->assertCreated()->json();
        $this->putJson('/api/quality/configuration',['entity_type'=>'product','entity_id'=>$product->id,'quality_inspection_mode'=>'required','quality_inspection_template_id'=>$template['id']])->assertOk();
        $this->postJson("/api/purchase-orders/{$order['id']}/receive",['warehouse_id'=>$warehouse['id'],'items'=>[['id'=>$order['items'][0]['id'],'accepted_quantity'=>4]],'idempotency_key'=>(string)Str::uuid()])->assertOk();
        $inspection=QualityInspection::firstOrFail();
        $this->postJson("/api/quality/inspections/{$inspection->id}/finalize",[
            'accepted_quantity'=>2,'rejected_quantity'=>2,'quarantine_quantity'=>0,'damaged_quantity'=>0,
            'results'=>[
                ['quality_checklist_item_id'=>$template['items'][0]['id'],'numeric_value'=>10],
                ['quality_checklist_item_id'=>$template['items'][1]['id'],'passed'=>false],
            ],
        ])->assertOk();
        $category=$this->postJson('/api/quality/defect-categories',['name'=>'Incorrect dimensions'])->assertCreated()->json();
        $defect=$this->postJson("/api/quality/inspections/{$inspection->id}/defects",['quality_defect_category_id'=>$category['id'],'severity'=>'CRITICAL','affected_quantity'=>2,'description'=>'Outside drawing tolerance.'])->assertCreated()->json();
        $claim=$this->postJson('/api/quality/claims',[
            'supplier_id'=>$supplier->id,'quality_inspection_id'=>$inspection->id,'requested_outcome'=>'replacement_requested',
            'defect_ids'=>[$defect['id']],'items'=>[['product_id'=>$product->id,'affected_quantity'=>2,'unit_value'=>3]],
        ])->assertCreated()->assertJsonPath('affected_value','6.00')->json();
        $this->postJson("/api/quality/claims/{$claim['id']}/resolve",['actual_resolution'=>'replacement_agreed','resolution_notes'=>'Supplier confirmed replacement.'])->assertOk()->assertJsonPath('status','RESOLVED');
        $revision=$this->postJson("/api/quality/inspections/{$inspection->id}/corrections",['reason'=>'Laboratory result received after finalization.'])->assertCreated()->json();
        $this->assertSame($inspection->id,$revision['revision_of_id']);
        $this->assertNotNull($inspection->fresh()->finalized_at);
        $this->assertSame('PARTIAL',$inspection->fresh()->status);
        $this->assertDatabaseHas('operational_exceptions',['exception_type'=>'critical_defect','severity'=>'critical']);
    }

    public function test_supplier_score_is_explainable_excludes_missing_dates_and_is_tenant_safe(): void
    {
        [$product,$supplier,$warehouse,$order]=$this->purchasingFixture('required',8,now()->addDays(2)->toDateString());
        $this->postJson("/api/purchase-orders/{$order['id']}/receive",['warehouse_id'=>$warehouse['id'],'received_at'=>now()->addDay()->toDateString(),'items'=>[['id'=>$order['items'][0]['id'],'accepted_quantity'=>8]],'idempotency_key'=>(string)Str::uuid()])->assertOk();
        $inspection=QualityInspection::firstOrFail();
        $this->postJson("/api/quality/inspections/{$inspection->id}/finalize",['accepted_quantity'=>8,'rejected_quantity'=>0,'quarantine_quantity'=>0,'damaged_quantity'=>0])->assertOk();
        $card=$this->getJson("/api/quality/suppliers/{$supplier->id}/scorecard")->assertOk()->json();
        $this->assertSame(100.0,(float)$card['delivery']['on_time_delivery_percent']);
        $this->assertSame(100.0,(float)$card['quality']['acceptance_percent']);
        $this->assertNotNull($card['overall_score']);
        $this->assertNotEmpty($card['explanation']);

        PurchaseOrder::query()->create(['company_id'=>$this->apiCompany->id,'supplier_id'=>$supplier->id,'warehouse_id'=>$warehouse['id'],'po_number'=>'PO-NODATE','status'=>'ordered','total_amount'=>0,'total_paid'=>0,'currency'=>'EUR']);
        $this->assertSame(100.0,(float)$this->getJson("/api/quality/suppliers/{$supplier->id}/scorecard")->json('delivery.on_time_delivery_percent'));

        $foreignCompany=Company::factory()->create();
        $foreignSupplier=Supplier::withoutEvents(fn()=>Supplier::withoutGlobalScopes()->create(['company_id'=>$foreignCompany->id,'name'=>'Foreign supplier']));
        $this->getJson("/api/quality/suppliers/{$foreignSupplier->id}/scorecard")->assertNotFound();
    }

    public function test_staff_permissions_separate_finalization_and_claim_resolution(): void
    {
        [$product,$supplier,$warehouse,$order]=$this->purchasingFixture('required',2);
        $this->postJson("/api/purchase-orders/{$order['id']}/receive",['warehouse_id'=>$warehouse['id'],'items'=>[['id'=>$order['items'][0]['id'],'accepted_quantity'=>2]],'idempotency_key'=>(string)Str::uuid()])->assertOk();
        $inspection=QualityInspection::firstOrFail();
        $staffToken='quality-staff';User::factory()->create(['company_id'=>$this->apiCompany->id,'role'=>'staff','api_token'=>hash('sha256',$staffToken),'email_verified_at'=>now()]);
        $this->withHeader('Authorization','Bearer '.$staffToken)->postJson("/api/quality/inspections/{$inspection->id}/finalize",['accepted_quantity'=>2,'rejected_quantity'=>0,'quarantine_quantity'=>0,'damaged_quantity'=>0])->assertForbidden();
        $this->withHeader('Authorization','Bearer '.$staffToken)->getJson('/api/quality/inspections')->assertOk();
    }

    public function test_checklist_edit_archive_and_used_check_protection_preserve_inspection_history(): void
    {
        [$product,,$warehouse,$order]=$this->purchasingFixture('required',5);
        $payload=['name'=>'Audit checklist','items'=>[['name'=>'Package intact','check_type'=>'pass_fail','is_required'=>true,'tolerance'=>1,'instructions'=>'Inspect packaging']]];
        $template=$this->postJson('/api/quality/templates',$payload)->assertCreated()->json();
        $payload['items'][0]['name']='Package and label intact';
        $template=$this->putJson('/api/quality/templates/'.$template['id'],$payload)->assertOk()->assertJsonPath('items.0.name','Package and label intact')->json();
        $product->update(['quality_inspection_template_id'=>$template['id']]);
        $this->postJson('/api/purchase-orders/'.$order['id'].'/receive',['warehouse_id'=>$warehouse['id'],'items'=>[['id'=>$order['items'][0]['id'],'accepted_quantity'=>5]],'idempotency_key'=>(string)Str::uuid()])->assertOk();
        $inspection=QualityInspection::firstOrFail();
        $this->assertSame($template['id'],$inspection->quality_inspection_template_id);
        $this->putJson('/api/quality/templates/'.$template['id'],[...$payload,'is_active'=>false])->assertOk()->assertJsonPath('is_active',false)->assertJsonPath('items.0.id',$template['items'][0]['id']);
        $changed=$payload;
        $changed['items'][0]['tolerance']=2;
        $this->putJson('/api/quality/templates/'.$template['id'],$changed)->assertUnprocessable()->assertJsonValidationErrors('items');
        $changed=$payload;
        $changed['items'][0]['instructions']='Ignore packaging';
        $this->putJson('/api/quality/templates/'.$template['id'],$changed)->assertUnprocessable()->assertJsonValidationErrors('items');
        $payload['items'][0]['name']='Different check';
        $this->putJson('/api/quality/templates/'.$template['id'],$payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->getJson('/api/quality/inspections/'.$inspection->id)->assertOk()->assertJsonPath('template.items.0.name','Package and label intact');
    }

    private function purchasingFixture(string $mode, int $quantity, ?string $expectedAt=null): array
    {
        $this->actingAsApiUser('admin');
        $category=Category::create($this->tenantAttributes(['name'=>'Quality stock']));
        $supplier=Supplier::create($this->tenantAttributes(['name'=>'Quality supplier']));
        $warehouse=$this->postJson('/api/warehouses',['name'=>'Main','code'=>'MAIN','is_default'=>true])->assertCreated()->json();
        $product=Product::create($this->tenantAttributes(['category_id'=>$category->id,'supplier_id'=>$supplier->id,'default_warehouse_id'=>$warehouse['id'],'name'=>'Inspected component','sku'=>'QI-'.Str::random(6),'quantity'=>0,'unit'=>'pcs','min_quantity'=>0,'price'=>5,'purchase_price'=>3,'quality_inspection_mode'=>$mode]));
        $order=$this->postJson('/api/purchase-orders',['supplier_id'=>$supplier->id,'warehouse_id'=>$warehouse['id'],'ordered_at'=>now()->toDateString(),'expected_at'=>$expectedAt,'currency'=>'EUR','status'=>'ordered','items'=>[['product_id'=>$product->id,'description'=>$product->name,'unit'=>'pcs','quantity'=>$quantity,'unit_price'=>3]]])->assertCreated()->json();
        return [$product,$supplier,$warehouse,$order];
    }
}
