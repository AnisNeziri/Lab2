<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Category,Product,Company,OperationalTask};
class ProductMaturitySearchTest extends TestCase
{
 use RefreshDatabase;
 private function product(string $name='Milano 04'): Product {
  $category=Category::create($this->tenantAttributes(['name'=>'Fabric']));
  return Product::create($this->tenantAttributes(['name'=>$name,'sku'=>'MIL-04','category_id'=>$category->id,'quantity'=>3420,'min_quantity'=>10,'unit'=>'m','price'=>5]));
 }
 public function test_search_preserves_known_availability_and_never_loads_customer_analysis(): void {
  $this->actingAsApiUser();$p=$this->product();
  $this->getJson('/api/search?q=MIL-04')->assertOk()->assertJsonPath('products.0.id',$p->id)->assertJsonPath('products.0.unit','m')->assertJsonPath('products.0.available_quantity',3420);
 }
 public function test_misspelled_product_is_a_suggestion_not_an_automatic_selection(): void {
  $this->actingAsApiUser();$p=$this->product();
  $this->getJson('/api/search?q=Milnao%2004')->assertOk()->assertJsonPath('products.0.id',$p->id)->assertJsonPath('products.0.similar_match',true)->assertJsonPath('products.0.url','/products?product='.$p->id);
 }
 public function test_search_never_suggests_foreign_company_records(): void {
  $this->actingAsApiUser();$p=$this->product('Foreign secret');$other=Company::factory()->create();\Illuminate\Support\Facades\DB::table('products')->where('id',$p->id)->update(['company_id'=>$other->id]);
  $this->getJson('/api/search?q=Foreign%20secret')->assertOk()->assertJsonMissingPath('products');
 }
 public function test_action_center_places_overdue_work_before_undated_tasks_and_preserves_mine_filter(): void {
  $this->actingAsApiUser();$user=\App\Models\User::where('company_id',$this->apiCompany->id)->firstOrFail();$base=['company_id'=>$this->apiCompany->id,'created_by'=>$user->id,'assigned_user_id'=>$user->id,'status'=>'open','priority'=>'normal'];
  $none=OperationalTask::create(array_replace($base,['title'=>'Undated urgent','priority'=>'urgent']));$overdue=OperationalTask::create($base+['title'=>'Overdue work','due_at'=>now()->subDay()]);
  $rows=$this->getJson('/api/action-center?view=mine')->assertOk()->json('tasks.data');$this->assertSame($overdue->id,$rows[0]['id']);$this->assertSame($none->id,$rows[1]['id']);
 }
 public function test_business_names_find_existing_decisions_without_changing_them(): void {
  $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$p=$this->product();$p->update(['min_quantity'=>5000]);
  app(\App\Services\EnterpriseDecisionService::class)->refresh($p->id);
  $decision=\App\Models\EnterpriseDecision::where('product_id',$p->id)->where('decision_type','REPLENISHMENT_DECISION')->firstOrFail();$original=$decision->getAttributes();
  foreach(['stockout','Restocking review','Rishikim i furnizimit','rekomandimet'] as $term)$this->getJson('/api/search?q='.urlencode($term))->assertOk()->assertJsonFragment(['id'=>$decision->id,'url'=>'/inventory-intelligence?view=decisions&decision='.$decision->id]);
  $this->assertSame($original,$decision->fresh()->getAttributes());
 }
}
