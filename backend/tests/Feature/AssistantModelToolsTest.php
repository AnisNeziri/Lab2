<?php
namespace Tests\Feature;

use App\Models\{Company,User,Product};
use App\Services\{AssistantModelTools,AssistantToolRunner};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,Http};
use Tests\TestCase;

class AssistantModelToolsTest extends TestCase
{
    use RefreshDatabase;
    private function actor(array $permissions):User {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $c=Company::factory()->create(['role_permissions'=>['staff'=>$permissions]]);
        $u=User::factory()->create(['company_id'=>$c->id,'role'=>'staff']);Auth::setUser($u);
        config(['assistant.provider'=>'ollama','assistant.local_model'=>'test','assistant.model_tools'=>true]);return $u;
    }
    private function proposal(string $tool,array $args=[]):void {
        Http::fake(['*'=>Http::response(['message'=>['content'=>json_encode(['calls'=>[['tool'=>$tool,'arguments'=>$args]],'clarification'=>''])]])]);
    }
    public function test_model_cannot_propose_a_write_or_unauthorized_tool():void {
        $this->actor(['inventory.view']);$this->proposal('post_journal');$context=[];
        $this->assertNull(app(AssistantModelTools::class)->reply(['question'=>'post a journal'],'conversation',$context));
        $this->assertDatabaseCount('journal_entries',0);$this->assertDatabaseCount('stock_movements',0);
    }
    public function test_model_invented_entity_id_is_not_executed():void {
        $this->actor(['inventory.view']);$this->proposal('get_product_availability',['product_id'=>987654]);$context=[];
        $this->assertNull(app(AssistantModelTools::class)->reply(['question'=>'that fabric'],'conversation',$context));
        $this->assertDatabaseCount('stock_movements',0);
    }
    public function test_model_timeout_uses_existing_fallback():void {
        $this->actor(['inventory.view']);Http::fake(fn()=>throw new \Illuminate\Http\Client\ConnectionException('test timeout'));$context=[];
        $this->assertNull(app(AssistantModelTools::class)->reply(['question'=>'stock'],'conversation',$context));
    }
    public function test_current_low_stock_cannot_answer_a_future_stockout_question():void {
        $this->actor(['inventory.view','analytics.view']);$this->proposal('get_low_stock_facts');$context=[];
        $this->assertNull(app(AssistantModelTools::class)->reply(['question'=>'Which products may run out before incoming shipments?'],'conversation',$context));
    }
    public function test_normalized_albanian_unpaid_salary_uses_unpaid_payroll_source():void {
        $this->assertSame('get_unpaid_payroll',app(\App\Services\Workforce\WorkforceAssistantFacts::class)->intent('cilat paga nuk jane paguar'));
    }
    public function test_permission_revoked_during_inference_prevents_tool_execution():void {
        $actor=$this->actor(['inventory.view']);$context=[];
        Http::fake(function()use($actor){$actor->company->update(['role_permissions'=>['staff'=>['dashboard.view']]]);return Http::response(['message'=>['content'=>json_encode(['calls'=>[['tool'=>'get_product_availability','arguments'=>[]]],'clarification'=>''])]]);});
        $this->assertNull(app(AssistantModelTools::class)->reply(['question'=>'stock'],'conversation',$context));$this->assertSame([],$context);
        $this->assertDatabaseCount('stock_movements',0);
    }
    public function test_typed_dates_reject_code_or_invalid_date():void {
        $this->actor(['analytics.view','analytics.finance']);
        try{app(AssistantToolRunner::class)->execute('get_sales_analytics',['period'=>'custom','from'=>'SELECT * FROM users','to'=>'2026-10-31']);$this->fail('Invalid date');}
        catch(\Illuminate\Validation\ValidationException){$this->assertTrue(true);}
    }
    public function test_real_encoder_keeps_catalogue_private_and_reads_stock_through_authority():void {
        if(!getenv('DC181_REAL_ENCODER'))$this->markTestSkipped('Real encoder integration is explicitly opt-in.');
        $root='C:/Users/anisi/.codex/recovery/dc18-1-20261104';
        config(['assistant.image_encoder'=>'siglip2','assistant.encoder_python'=>$root.'/encoder-venv/Scripts/python.exe','assistant.encoder_path'=>$root.'/siglip2','assistant.encoder_revision'=>'75de2d55ec2d0b4efc50b3e9ad70dba96a7b2fa2']);
        $owner=$this->actor(['inventory.view']);
        $bytes=file_get_contents('C:/Users/anisi/.codex/recovery/dc18-20261104/evidence/synthetic-fabric.png');
        Product::create(['name'=>'Authorized image','sku'=>'OWN-IMAGE','price'=>20,'quantity'=>0,'unit'=>'m','image_data'=>base64_encode($bytes),'image_mime'=>'image/png']);
        $other=Company::factory()->create();Auth::setUser(User::factory()->create(['company_id'=>$other->id,'role'=>'admin']));
        Product::create(['name'=>'Private other image','sku'=>'OTHER-IMAGE','price'=>20,'quantity'=>0,'unit'=>'m','image_data'=>base64_encode($bytes),'image_mime'=>'image/png']);
        Auth::setUser($owner);
        $image=\Illuminate\Http\UploadedFile::fake()->createWithContent('sample.png',$bytes);
        $r=app(\App\Services\AssistantVisualSearch::class)->search($image);
        $this->assertSame('siglip2-base-patch16-224',$r['method']);$this->assertCount(1,$r['results']);$this->assertSame('OWN-IMAGE',$r['results'][0]['sku']);
        $this->assertFalse($r['exact_product_confirmed']);$this->assertFalse($r['retained_upload']);$this->assertEquals(0,$r['results'][0]['stock']['available']);
        $this->assertDatabaseCount('stock_movements',0);$this->assertDatabaseCount('journal_entries',0);
    }
}
