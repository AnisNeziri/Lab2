<?php
namespace Tests\Feature;

use App\Models\{Company,Product,User};
use App\Services\{AimsToolRegistry,CatalogReadAccess};
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CatalogConfidentialityTest extends TestCase
{
 use RefreshDatabase;
 private function catalog(array $permissions):Product {
  $this->seed(RolePermissionSeeder::class);
  $company=Company::factory()->create(['role_permissions'=>['staff'=>$permissions]]);
  User::factory()->create(['company_id'=>$company->id,'role'=>'staff','email_verified_at'=>now(),'api_token'=>hash('sha256','catalog-test')]);
  $this->withHeader('Authorization','Bearer catalog-test');
  return Product::create(['company_id'=>$company->id,'name'=>'Protected fabric','sku'=>'PROTECTED-1','unit'=>'m','quantity'=>0,'price'=>20,'purchase_price'=>12,'weighted_average_cost'=>12,'inventory_value'=>0,'selling_price'=>20]);
 }
 public function test_hr_cannot_read_catalog_stock_cost_exports_or_indirect_product_context():void {
  $p=$this->catalog(['dashboard.view']);
  foreach(['/api/products','/api/products?reference_only=1','/api/products/'.$p->id,'/api/products/lookup?sku=PROTECTED-1','/api/products/export','/api/shelves/A1/products','/api/inventory/products/'.$p->id,'/api/entity-context/product/'.$p->id,'/api/product-suppliers','/api/suppliers'] as $url)$this->getJson($url)->assertForbidden();
  $search=$this->getJson('/api/search?q=Protected')->assertOk()->json();
  $this->assertEmpty($search['products']??[]);
  $this->assertNotContains('get_product_availability',array_column(app(AimsToolRegistry::class)->catalog(),'name'));
 }
 public function test_stock_reader_has_quantities_but_not_acquisition_cost_or_supplier_catalogue():void {
  $p=$this->catalog(['inventory.view']);
  $data=$this->getJson('/api/products/'.$p->id)->assertOk()->json('product');
  $this->assertArrayHasKey('quantity',$data);$this->assertArrayHasKey('inventory',$data);
  foreach(['purchase_price','weighted_average_cost','inventory_value','supplier_catalogue','supplier'] as $key)$this->assertArrayNotHasKey($key,$data);
  $this->getJson('/api/products/export')->assertForbidden();
 }
 public function test_sales_reference_is_explicit_minimal_and_still_has_sell_price_and_units():void {
  $p=$this->catalog(['fulfillment.view']);
  $this->getJson('/api/products')->assertForbidden();
  $data=$this->getJson('/api/products?reference_only=1')->assertOk()->json('data.0');
  $this->assertSame($p->id,$data['id']);$this->assertSame('20.00',$data['selling_price']);$this->assertArrayHasKey('unit',$data);
  foreach(['quantity','available_quantity','warehouse_stock','inventory','supplier_catalogue','purchase_price'] as $key)$this->assertArrayNotHasKey($key,$data);
  $this->getJson('/api/products/'.$p->id.'?reference_only=1')->assertOk()->assertJsonMissingPath('movements');
  Auth::user()->company->update(['role_permissions'=>['staff'=>['invoices.manage']]]);
  $this->getJson('/api/products')->assertForbidden();
  $this->getJson('/api/products?reference_only=1')->assertOk()->assertJsonPath('data.0.selling_price','20.00')->assertJsonMissingPath('data.0.quantity')->assertJsonMissingPath('data.0.purchase_price');
 }
 public function test_cost_authority_keeps_costs_and_tenant_binding_still_denies_foreign_products():void {
  $p=$this->catalog(['inventory.view','supplier_catalogue.view']);
  $this->getJson('/api/products/'.$p->id)->assertOk()->assertJsonPath('product.purchase_price','12.00');
  $prior=Auth::user();$foreignCompany=Company::factory()->create();Auth::setUser(User::factory()->create(['company_id'=>$foreignCompany->id]));
  $other=Product::create(['name'=>'Foreign fabric','sku'=>'FOREIGN','unit'=>'m','quantity'=>0,'price'=>20]);Auth::setUser($prior);
  $this->getJson('/api/products/'.$other->id)->assertNotFound();
 }
 public function test_cost_redaction_does_not_remove_operational_supplier_identity_or_sale_prices():void {
  $redacted=app(CatalogReadAccess::class)->redact(['supplier_id'=>7,'unit_price'=>'20.00','product'=>['sku'=>'A','supplier_id'=>7,'purchase_price'=>12,'selling_price'=>20]]);
  $this->assertSame(7,$redacted['supplier_id']);$this->assertSame('20.00',$redacted['unit_price']);
  $this->assertArrayNotHasKey('purchase_price',$redacted['product']);$this->assertSame(20,$redacted['product']['selling_price']);
 }
}
