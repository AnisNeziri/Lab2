<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,DB};
use App\Models\{Automation,Company,CmsPage,DailySale,PurchaseOrderPayment};
class DC9WorkflowClarityTest extends TestCase {
 use RefreshDatabase;
 public function test_rules_search_and_area_cover_unloaded_pages_without_cross_company_records(): void {
  $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$owner=Auth::user();
  for($i=0;$i<61;$i++)Automation::create(['name'=>$i===0?'Oldest target':'Rule '.$i,'trigger'=>'inventory.low_stock','conditions'=>['all'=>[]],'actions'=>[['type'=>'create_task','title'=>'Review']],'created_by'=>$owner->id,'priority'=>'normal']);
  $foreign=Company::factory()->create();DB::table('automations')->insert(['company_id'=>$foreign->id,'name'=>'Foreign target','trigger'=>'inventory.low_stock','conditions'=>'{}','actions'=>'[]','created_by'=>$owner->id,'priority'=>'normal','created_at'=>now(),'updated_at'=>now()]);
  $this->getJson('/api/automations?per_page=6&page=11')->assertOk()->assertJsonPath('total',61)->assertJsonCount(1,'data')->assertJsonPath('data.0.name','Oldest target');
  $this->getJson('/api/automations?search=Oldest&area=inventory&per_page=6')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.name','Oldest target');
  $this->getJson('/api/automations?search=target&area=finance')->assertOk()->assertJsonPath('total',0);
  $this->getJson('/api/automations?search=%25')->assertOk()->assertJsonPath('total',0);
  $this->getJson('/api/automations?per_page=999')->assertUnprocessable();
 }
 public function test_public_cms_is_published_global_only_and_tenant_admin_cannot_edit(): void {
  $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();
  $id=DB::table('cms_pages')->insertGetId(['company_id'=>null,'slug'=>'landing-hero-title','title'=>'Global','content'=>'Published global','is_published'=>true,'created_at'=>now(),'updated_at'=>now()]);
  DB::table('cms_pages')->insert(['company_id'=>$this->apiCompany->id,'slug'=>'about-section','title'=>'Tenant secret','content'=>'Never public','is_published'=>true,'created_at'=>now(),'updated_at'=>now()]);
  $this->getJson('/api/cms/published')->assertOk()->assertSee('Published global')->assertDontSee('Never public');
  $this->getJson('/api/cms')->assertForbidden();$this->putJson('/api/cms/'.$id,['content'=>'Unauthorized'])->assertNotFound();
  $this->actingAsApiUser('superadmin');$user=\App\Models\User::where('api_token',hash('sha256','test-token-superadmin'))->firstOrFail();$user->company_id=null;$user->save();
  $this->getJson('/api/cms')->assertOk()->assertJsonPath('0.id',$id);
  $this->putJson('/api/cms/'.$id,['content'=>'Updated platform','is_published'=>false])->assertOk();
  $this->getJson('/api/cms/landing-hero-title')->assertNotFound();
 }
 public function test_operational_cash_discloses_excluded_sources_and_keeps_currency_separate(): void {
  $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();
  DailySale::create(['sale_number'=>'DC9-RECEIPT','sale_date'=>today(),'status'=>'finalized','total_amount'=>100,'paid_amount'=>75]);
  $result=app(\App\Services\FinanceService::class)->cashFlow(today()->toDateString(),today()->toDateString());
  $this->assertSame('operational_activity',$result['basis']);$this->assertFalse($result['summary']['reconciled']);
  $this->assertSame('75.00',$result['exclusions'][0]['amounts'][0]['amount']);$this->assertSame(1,$result['exclusions'][0]['count']);
  $this->assertEquals(0,$result['summary']['cash_in']);$this->assertSame('/daily-sales?date='.today()->toDateString(),$result['exclusions'][0]['url']);
 }
}
