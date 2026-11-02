<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,DB};
use App\Models\{Customer,User,Company};
class DC10OpeningReadinessTest extends TestCase {
 use RefreshDatabase;
 public function test_opening_review_is_scoped_audited_and_cannot_approve_or_post_missing_sources(): void {
  $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$owner=Auth::user();
  $customer=Customer::create($this->tenantAttributes(['name'=>'Legacy source requiring statement','current_debt'=>100,'current_credit'=>0]));
  $this->assertDatabaseCount('accounting_exceptions',0);
  $r=$this->getJson('/api/accounting/opening-review')->assertOk()->assertJsonPath('status','BLOCKED')->json();
  $ar=collect($r['rows'])->firstWhere('control','accounts_receivable');$this->assertSame('100.00',$ar['difference']);$this->assertTrue($ar['source_required']);$this->assertSame('BLOCKED_SOURCE_REQUIRED',$ar['opening_status']);
  $this->assertDatabaseCount('accounting_exceptions',0);$this->assertDatabaseCount('journal_entries',0);
  $body=['classification'=>'unattributed_legacy_record','note'=>'Obtain independently reviewed customer statement before any correction.','responsible_user_id'=>(string)$owner->id,'review_state'=>'under_review','fingerprint'=>$ar['fingerprint']];
  $this->putJson('/api/accounting/opening-review/accounts_receivable',$body)->assertOk()->assertJsonPath('status','BLOCKED');
  $this->assertDatabaseHas('accounting_exceptions',['exception_key'=>'opening-review:accounts_receivable','status'=>'open']);$this->assertDatabaseHas('audit_logs',['action'=>'accounting.opening.review_recorded']);
  $this->putJson('/api/accounting/opening-review/accounts_receivable',array_replace($body,['review_state'=>'approved']))->assertUnprocessable();
  $foreign=Company::factory()->create();$person=User::factory()->create(['company_id'=>$foreign->id]);
  $this->putJson('/api/accounting/opening-review/accounts_receivable',array_replace($body,['responsible_user_id'=>$person->id]))->assertUnprocessable();
  $customer->update(['current_debt'=>101]);$this->putJson('/api/accounting/opening-review/accounts_receivable',$body)->assertUnprocessable();
  $this->assertDatabaseCount('journal_entries',0);$this->assertEquals('101.00',$customer->fresh()->current_debt);
  $this->actingAsApiUser('manager');$this->getJson('/api/accounting/opening-review')->assertForbidden();
 }
 public function test_verified_database_rehearsal_does_not_certify_opening_encrypted_restore_or_cutover(): void {
  $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$original=app()->storagePath();$temp=storage_path('app/dc10-readiness-'.bin2hex(random_bytes(6)));mkdir($temp.'/framework',0700,true);
  try {
   app()->useStoragePath($temp);$c=config('database.connections.'.config('database.default'));$scope=hash('sha256',($c['host']??'').':'.($c['port']??'').':'.($c['database']??''));
   file_put_contents($temp.'/framework/aims-recovery-rehearsal.json',json_encode(['app_key_fingerprint'=>hash('sha256',config('app.key')),'source_scope'=>$scope,'logical_restore_verified'=>true,'schema_objects_validated'=>true,'key_compatible'=>true,'target_version'=>'11.4.13','tables'=>215,'checked_at'=>now()->toIso8601String(),'active_cutover_performed'=>false]));
   $this->getJson('/api/system-integrity')->assertOk()->assertJsonPath('operational_readiness.database_recovery.status','VERIFIED_ISOLATED')->assertJsonPath('operational_readiness.financial_opening.status','BLOCKED_SOURCE_REQUIRED')->assertJsonPath('operational_readiness.encrypted_installation_restore.status','NOT_VERIFIED')->assertJsonPath('operational_readiness.live_company_operation.status','BLOCKED')->assertJsonPath('operational_readiness.live_company_operation.active_cutover_performed',false);
   $this->assertDatabaseCount('journal_entries',0);
  } finally {app()->useStoragePath($original);}
 }
}
