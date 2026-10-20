<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{BusinessEvent, Company, InventoryCountSession, Product, StockMovement, User};
use Illuminate\Support\Facades\Cache;

class ProductMaturityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function countFixture(): array
    {
        $this->actingAsApiUser();
        $warehouse=$this->postJson('/api/warehouses',['name'=>'PM4 warehouse','code'=>'PM4','is_default'=>true])->assertCreated()->json();
        $category=\App\Models\Category::create($this->tenantAttributes(['name'=>'PM4 category']));
        $product=$this->postJson('/api/products',['name'=>'PM4 item','category_id'=>$category->id,'unit'=>'pcs','quantity'=>10,'min_quantity'=>1,'price'=>5,'purchase_price'=>2,'selling_price'=>5,'default_warehouse_id'=>$warehouse['id']])->assertCreated()->json();
        $count=$this->postJson('/api/inventory-counts',['warehouse_id'=>$warehouse['id'],'stock_states'=>['available']])->assertCreated()->json();
        return [$product,$count];
    }

    public function test_unused_count_draft_can_be_deleted_without_stock_changes_and_keeps_audit(): void
    {
        [$p,$c]=$this->countFixture();$before=StockMovement::count();
        $this->deleteJson('/api/inventory-counts/'.$c['id'])->assertOk();
        $this->assertDatabaseMissing('inventory_count_sessions',['id'=>$c['id']]);
        $this->assertDatabaseMissing('inventory_count_items',['inventory_count_session_id'=>$c['id']]);
        $this->assertSame($before,StockMovement::count());
        $this->assertEquals(10,Product::find($p['id'])->quantity);
        $this->assertDatabaseHas('business_events',['event_type'=>'inventory.count_draft_deleted','entity_id'=>$c['id']]);
    }

    public function test_recorded_submitted_and_approved_counts_cannot_be_deleted(): void
    {
        [$p,$c]=$this->countFixture();$path='/api/inventory-counts/'.$c['id'];
        $this->postJson($path.'/record',['items'=>[['count_item_id'=>$c['items'][0]['id'],'counted_quantity'=>9]]])->assertOk();
        $this->deleteJson($path)->assertUnprocessable();
        $this->postJson($path.'/submit')->assertOk();
        $this->deleteJson($path)->assertUnprocessable();
        $this->postJson($path.'/approve',['reason'=>'Confirmed physical count'])->assertOk();
        $this->deleteJson($path)->assertUnprocessable();
        $this->postJson($path.'/cancel',['reason'=>'Cannot cancel approved history'])->assertUnprocessable();
        $this->assertEquals(9,Product::find($p['id'])->quantity);
        $this->assertDatabaseHas('inventory_count_sessions',['id'=>$c['id'],'status'=>'approved']);
    }

    public function test_creator_can_cancel_own_unsubmitted_request_without_approval_permission(): void
    {
        [$p,$c]=$this->countFixture();
        $role=\App\Models\Role::where('slug','admin')->firstOrFail();
        $role->permissions()->detach(\App\Models\Permission::where('slug','inventory.counts.approve')->value('id'));
        Cache::forget('role_permissions:admin');
        $before=StockMovement::count();
        $this->postJson('/api/inventory-counts/'.$c['id'].'/cancel',['reason'=>'Review request no longer needed'])->assertOk()->assertJsonPath('status','cancelled');
        $this->assertSame($before,StockMovement::count());
        $this->assertEquals(10,Product::find($p['id'])->quantity);
        $this->assertDatabaseHas('business_events',['event_type'=>'inventory.count_cancelled','entity_id'=>$c['id']]);
    }

    public function test_foreign_count_and_linked_downstream_count_draft_are_protected(): void
    {
        [, $c]=$this->countFixture();
        $other=Company::factory()->create();
        \Illuminate\Support\Facades\DB::table('inventory_count_sessions')->where('id',$c['id'])->update(['company_id'=>$other->id]);
        $this->deleteJson('/api/inventory-counts/'.$c['id'])->assertNotFound();
        $this->postJson('/api/inventory-counts/'.$c['id'].'/cancel',['reason'=>'Foreign request'])->assertNotFound();
        \Illuminate\Support\Facades\DB::table('inventory_count_sessions')->where('id',$c['id'])->update(['company_id'=>$this->apiCompany->id]);
        \App\Models\OperationalTask::create(['company_id'=>$this->apiCompany->id,'title'=>'Linked count request','source_type'=>'InventoryCountSession','source_id'=>$c['id']]);
        $this->deleteJson('/api/inventory-counts/'.$c['id'])->assertUnprocessable();
    }
}
