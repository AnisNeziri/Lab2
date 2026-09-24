<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformAuditRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_shelf_details_are_scoped_to_selected_warehouse_and_company(): void
    {
        $this->actingAsApiUser();
        $category=Category::create($this->tenantAttributes(['name'=>'Map stock']));
        $product=Product::create($this->tenantAttributes(['category_id'=>$category->id,'name'=>'Located item','sku'=>'MAP-1','quantity'=>30,'unit'=>'pcs','price'=>5]));
        $warehouses=[];
        foreach ([10,20] as $index=>$quantity) {
            $warehouse=\App\Models\Warehouse::create($this->tenantAttributes(['name'=>'Warehouse '.$index,'code'=>'MAP'.$index]));
            $location=\App\Models\WarehouseLocation::create($this->tenantAttributes(['warehouse_id'=>$warehouse->id,'code'=>'A1','name'=>'A1','path'=>'A1','type'=>'bin','floor_level'=>1]));
            \App\Models\WarehouseSection::create($this->tenantAttributes(['warehouse_id'=>$warehouse->id,'warehouse_location_id'=>$location->id,'code'=>'A1','name'=>'A1','floor_level'=>1]));
            \App\Models\WarehouseStock::create($this->tenantAttributes(['warehouse_id'=>$warehouse->id,'location_id'=>$location->id,'product_id'=>$product->id,'quantity'=>$quantity,'available_quantity'=>$quantity]));
            $warehouses[]=$warehouse;
            $this->getJson('/api/shelves/A1/products?warehouse_id='.$warehouse->id)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id',$product->id)->assertJsonPath('0.shelf_available_quantity',$quantity);
        }
        $this->getJson('/api/shelves/NOT-THERE/products?warehouse_id='.$warehouses[1]->id)->assertOk()->assertExactJson([]);
        $company=\App\Models\Company::factory()->create();
        $foreign=\App\Models\Warehouse::withoutEvents(fn()=>\App\Models\Warehouse::withoutGlobalScopes()->create(['company_id'=>$company->id,'code'=>'FOREIGN','name'=>'Foreign']));
        $this->getJson('/api/shelves/A1/products?warehouse_id='.$foreign->id)->assertUnprocessable();
    }

    public function test_read_endpoints_enforce_existing_permissions(): void
    {
        $this->actingAsApiUser('staff');
        $role = Role::where('slug', 'staff')->firstOrFail();
        $role->permissions()->detach();
        Cache::forget('role_permissions:staff');
        foreach (['/dashboard','/dashboard/sales-analytics','/reports','/stock-movements','/stock-movements/export'] as $path) {
            $this->getJson('/api'.$path)->assertForbidden();
        }
    }

    public function test_partial_awards_convert_separately_and_retries_do_not_duplicate_purchase_orders(): void
    {
        [$request,$rfq,$supplier] = $this->rfq();
        $quote = $this->postJson('/api/rfqs/'.$rfq.'/quotes', $this->quote($supplier, $request['items']))->assertCreated()->json();
        $this->getJson('/api/rfqs/'.$rfq.'/comparison')->assertOk()->assertJsonPath('quotes.0.items.0.line_subtotal','30.30');
        $this->postJson('/api/rfqs/'.$rfq.'/award',['quote_item_ids'=>[$quote['items'][0]['id']]])->assertOk()->assertJsonPath('status','partially_awarded');
        $key = (string) Str::uuid();
        $this->postJson('/api/rfqs/'.$rfq.'/convert',['idempotency_key'=>$key])->assertOk()->assertJsonCount(1,'purchase_orders');
        $this->postJson('/api/rfqs/'.$rfq.'/convert',['idempotency_key'=>$key])->assertOk()->assertJsonCount(1,'purchase_orders');
        $this->postJson('/api/rfqs/'.$rfq.'/award',['quote_item_ids'=>[$quote['items'][0]['id']]])->assertUnprocessable();
        $this->postJson('/api/rfqs/'.$rfq.'/award',['quote_item_ids'=>[$quote['items'][1]['id']]])->assertOk()->assertJsonPath('status','awarded');
        $this->postJson('/api/rfqs/'.$rfq.'/convert',['idempotency_key'=>(string) Str::uuid()])->assertOk()->assertJsonCount(2,'purchase_orders');
        $this->assertDatabaseCount('purchase_orders',2);
        $this->getJson('/api/rfqs/'.$rfq.'/comparison')->assertOk()->assertJsonCount(2,'awards')->assertJsonPath('awards.0.status','converted');
    }

    public function test_quote_scope_revision_and_minimum_quantity_are_checked_on_award(): void
    {
        [$request,$rfq,$supplier] = $this->rfq();
        $payload = $this->quote($supplier,$request['items']);
        $payload['items'][0]['purchase_request_item_id'] = 999999;
        $this->postJson('/api/rfqs/'.$rfq.'/quotes',$payload)->assertUnprocessable();
        $payload = $this->quote($supplier,$request['items']);
        $old = $this->postJson('/api/rfqs/'.$rfq.'/quotes',$payload)->assertCreated()->json();
        $payload['items'][0]['minimum_order_quantity'] = 5;
        $current = $this->postJson('/api/rfqs/'.$rfq.'/quotes',$payload)->assertCreated()->json();
        $this->postJson('/api/rfqs/'.$rfq.'/award',['quote_item_ids'=>[$old['items'][0]['id']]])->assertUnprocessable()->assertJsonValidationErrors('quote_item_ids');
        $this->postJson('/api/rfqs/'.$rfq.'/award',['quote_item_ids'=>[$current['items'][0]['id']]])->assertUnprocessable()->assertJsonValidationErrors('quote_item_ids');
        $this->assertDatabaseCount('procurement_awards',0);
        $this->postJson('/api/rfqs/'.$rfq.'/award',['quote_item_ids'=>[$current['items'][1]['id']]])->assertOk();
    }

    public function test_draft_request_can_be_edited_without_resending_optional_requested_date(): void
    {
        [$request] = $this->rfq(false);
        $this->putJson('/api/purchase-requests/'.$request['id'],['currency'=>'EUR','notes'=>'Revised','items'=>array_map(fn($item)=>collect($item)->only(['product_id','description','unit','quantity','estimated_unit_price'])->all(),$request['items'])])
            ->assertOk()->assertJsonPath('notes','Revised')->assertJsonPath('status','draft');
    }

    private function rfq(bool $issue = true): array
    {
        $this->actingAsApiUser('admin');
        $category = Category::create($this->tenantAttributes(['name'=>'Audit']));
        $items = [];
        foreach (['A','B'] as $name) {
            $product = Product::create($this->tenantAttributes(['category_id'=>$category->id,'name'=>'Audit '.$name,'sku'=>'AUD-'.$name,'quantity'=>0,'unit'=>'pcs','price'=>1]));
            $items[] = ['product_id'=>$product->id,'description'=>$product->name,'unit'=>'pcs','quantity'=>3,'estimated_unit_price'=>'10.10'];
        }
        $supplier = Supplier::create($this->tenantAttributes(['name'=>'Audit supplier']));
        $request = $this->postJson('/api/purchase-requests',['currency'=>'EUR','items'=>$items])->assertCreated()->json();
        if (!$issue) return [$request];
        $this->postJson('/api/purchase-requests/'.$request['id'].'/submit')->assertOk();
        $rfq = $this->postJson('/api/purchase-requests/'.$request['id'].'/rfqs',['supplier_ids'=>[$supplier->id]])->assertCreated()->json('id');
        $this->postJson('/api/rfqs/'.$rfq.'/issue')->assertOk();
        return [$request,$rfq,$supplier->id];
    }

    private function quote(int $supplier, array $items): array
    {
        return ['supplier_id'=>$supplier,'currency'=>'EUR','valid_until'=>today()->toDateString(),'items'=>array_map(fn($item)=>['purchase_request_item_id'=>$item['id'],'offered_quantity'=>3,'unit_price'=>'10.10','minimum_order_quantity'=>1,'lead_time_days'=>3],$items)];
    }
}
