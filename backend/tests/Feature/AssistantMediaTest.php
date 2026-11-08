<?php
namespace Tests\Feature;

use App\Models\{Company,User,PilotFeedback};
use App\Services\{AssistantConversation,AssistantPlanner,AssistantSupport,LocalAssistantProvider};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,Http};
use Tests\TestCase;

class AssistantMediaTest extends TestCase
{
    use RefreshDatabase;
    private function actor(int $company=0):User {
        $c=$company?:Company::factory()->create()->id;
        $u=User::factory()->create(['company_id'=>$c,'role'=>'admin']);Auth::setUser($u);return $u;
    }
    public function test_followup_reuses_query_period_not_answer_and_preserves_entity_name():void {
        $q=app(AssistantConversation::class)->question('What about the month before?',['last_intent'=>'sales','sales_period'=>['from'=>'2026-01-01','to'=>'2026-01-31']]);
        $this->assertSame('sales 2025-12-01 2025-12-31',$q);
        $this->assertSame('What about the month before?',app(AssistantConversation::class)->question('What about the month before?',[]));
        $plan=app(AssistantPlanner::class)->plan('Sa metra stof milano 04 na kan met?');
        $this->assertSame('product',$plan['type']);$this->assertSame('milano 04',$plan['term']);
    }
    public function test_support_requires_confirmation_is_redacted_idempotent_and_tenant_isolated():void {
        $owner=$this->actor();$service=app(AssistantSupport::class);
        $draft=$service->prepare(['title'=>'Cannot save','description'=>'token=private-secret email@private.test','page'=>'/order-hub?token=abc']);
        $this->assertStringNotContainsString('private-secret',json_encode($draft));$this->assertStringNotContainsString('email@private.test',json_encode($draft));
        $this->assertDatabaseCount('pilot_feedback',0);
        try{$service->submit($draft['id'],['confirmed'=>false]);$this->fail('Confirmation required');}catch(\Illuminate\Validation\ValidationException){}
        $receipt=$service->submit($draft['id'],['confirmed'=>true]);
        $this->assertSame($receipt,$service->submit($draft['id'],['confirmed'=>true]));$this->assertDatabaseCount('pilot_feedback',1);
        $cache=\Illuminate\Support\Facades\Cache::store('file');$key='assistant-support:'.$owner->company_id.':'.$owner->id.':'.$draft['id'];
        $stored=$cache->get($key);unset($stored['receipt']);$cache->put($key,$stored,1800);
        $this->assertSame($receipt,$service->submit($draft['id'],['confirmed'=>true]));$this->assertDatabaseCount('pilot_feedback',1);
        $this->assertSame('received_internal',$service->status($receipt['id'])['status']);
        $this->actor();
        try{$service->status($receipt['id']);$this->fail('Tenant leakage');}catch(\Illuminate\Database\Eloquent\ModelNotFoundException){}
        try{$service->submit($draft['id'],['confirmed'=>true]);$this->fail('Draft leakage');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(404,$e->getStatusCode());}
    }
    public function test_vision_disabled_or_nonlocal_never_sends_company_image():void {
        Http::fake();config(['assistant.provider'=>'deterministic']);
        $this->assertSame('unavailable',app(LocalAssistantProvider::class)->screenshot('private')['state']);
        config(['assistant.provider'=>'ollama','assistant.local_vision_model'=>'optional','assistant.local_url'=>'https://external.example']);
        $this->assertSame('invalid_local_configuration',app(LocalAssistantProvider::class)->screenshot('private')['state']);Http::assertNothingSent();
    }
    public function test_visual_candidates_cannot_cross_tenants_and_permission_precedes_decoding():void {
        $owner=$this->actor();$company=$owner->company;
        $company->update(['role_permissions'=>['viewer'=>['dashboard.view']]]);$owner->update(['role'=>'viewer']);
        $image=\Illuminate\Http\UploadedFile::fake()->createWithContent('fabric.png','not-an-image');
        try{app(\App\Services\AssistantVisualSearch::class)->search($image);$this->fail('Permission required');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        // Global scope still excludes other companies, including private image data.
        $owner->update(['role'=>'admin']);
        $other=$this->actor();\App\Models\Product::create(['name'=>'Other private fabric','sku'=>'OTHER','unit'=>'m','price'=>20,'quantity'=>0,'image_data'=>'private']);
        Auth::setUser($owner);$this->assertSame(0,\App\Models\Product::whereNotNull('image_data')->count());
    }
    public function test_vision_output_is_unverified_redacted_and_never_executed():void {
        config(['assistant.provider'=>'ollama','assistant.local_vision_model'=>'test','assistant.local_url'=>'http://127.0.0.1:11434']);
        Http::fake(['*'=>Http::response(['message'=>['content'=>json_encode(['observation'=>'token=private admin@company.test','suggested_review'=>'Check the order form'])]])]);
        $result=app(LocalAssistantProvider::class)->screenshot('synthetic');
        $this->assertFalse($result['cause_verified']);$this->assertStringNotContainsString('private',json_encode($result));
        $this->assertDatabaseCount('journal_entries',0);$this->assertDatabaseCount('stock_movements',0);
    }
}
