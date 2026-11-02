<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Commercial\Entitlements;
use App\Services\Commercial\InstallationIdentity;
use App\Services\JwtService;
use App\Services\Security\PasskeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DC8SecurityCommercialTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    private array $tokens;
    private array $temporary=[];
    protected function setUp(): void
    {
        parent::setUp();Queue::fake();$this->actingAsApiUser();
        $this->owner=User::where('company_id',$this->apiCompany->id)->firstOrFail();
        $this->tokens=app(JwtService::class)->issueTokens($this->owner);
        $this->withHeader('Authorization','Bearer '.$this->tokens['access_token']);
        config(['security.passkeys.rp_id'=>'erp.example.test','security.passkeys.origins'=>['https://erp.example.test']]);
    }
    protected function tearDown(): void { foreach($this->temporary as $file) if(is_file($file))unlink($file);parent::tearDown(); }
    private function cborHead(int $major,int $size): string {return $size<24?chr(($major<<5)|$size):($size<256?chr(($major<<5)|24).chr($size):chr(($major<<5)|25).pack('n',$size));}
    private function bytes(string $x): string {return $this->cborHead(2,strlen($x)).$x;}
    private function text(string $x): string {return $this->cborHead(3,strlen($x)).$x;}
    private function registration(array $options,?string $origin=null): array
    {
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$detail=openssl_pkey_get_details($key);
        $id=random_bytes(32);
        // Test fixture only: a real ES256 credential, CBOR none attestation and UV/UP flags.
        // OpenSSL can omit a leading zero; an ES256 COSE coordinate is always 32 bytes.
        $cose="\xa5\x01\x02\x03\x26\x20\x01\x21".$this->bytes(str_pad($detail['ec']['x'],32,"\0",STR_PAD_LEFT))."\x22".$this->bytes(str_pad($detail['ec']['y'],32,"\0",STR_PAD_LEFT));
        $auth=hash('sha256','erp.example.test',true)."\x45".pack('N',0).str_repeat("\0",16).pack('n',strlen($id)).$id.$cose;
        $att="\xa3".$this->text('fmt').$this->text('none').$this->text('attStmt')."\xa0".$this->text('authData').$this->bytes($auth);
        $client=json_encode(['type'=>'webauthn.create','challenge'=>$options['publicKey']['challenge'],'origin'=>$origin??'https://erp.example.test','crossOrigin'=>false]);
        return [['challenge_id'=>$options['challenge_id'],'name'=>'Synthetic ES256','rawId'=>PasskeyService::encode($id),
            'response'=>['clientDataJSON'=>PasskeyService::encode($client),'attestationObject'=>PasskeyService::encode($att),'transports'=>['internal']]],$key,$id];
    }
    private function assertion(array $options,$key,string $id,int $counter=1): array
    {
        $client=json_encode(['type'=>'webauthn.get','challenge'=>$options['publicKey']['challenge'],'origin'=>'https://erp.example.test','crossOrigin'=>false]);
        $auth=hash('sha256','erp.example.test',true)."\x05".pack('N',$counter);openssl_sign($auth.hash('sha256',$client,true),$sig,$key,OPENSSL_ALGO_SHA256);
        return ['challenge_id'=>$options['challenge_id'],'rawId'=>PasskeyService::encode($id),'response'=>['clientDataJSON'=>PasskeyService::encode($client),'authenticatorData'=>PasskeyService::encode($auth),'signature'=>PasskeyService::encode($sig)]];
    }
    public function test_passkey_crypto_origin_replay_ownership_and_revocation(): void
    {
        $o=$this->postJson('/api/security/passkeys/register/options')->assertOk()->json();[$v,$key,$id]=$this->registration($o);
        $credential=$this->postJson('/api/security/passkeys/register/verify',$v)->assertOk()->json('id');
        $this->postJson('/api/security/passkeys/register/verify',$v)->assertUnprocessable();
        $wrong=$this->postJson('/api/security/passkeys/register/options')->assertOk()->json();[$bad]=$this->registration($wrong,'https://evil.example.test');
        $this->postJson('/api/security/passkeys/register/verify',$bad)->assertUnprocessable();
        $this->assertNotNull(DB::table('auth_challenges')->find($wrong['challenge_id'])->consumed_at);
        $login=$this->postJson('/api/passkeys/login/options')->assertOk()->json();$assertion=$this->assertion($login,$key,$id);
        $tokens=$this->postJson('/api/passkeys/login/verify',$assertion)->assertOk()->assertJsonPath('user.id',$this->owner->id)->json();
        $this->postJson('/api/passkeys/login/verify',$assertion)->assertUnprocessable();
        $this->withHeader('Authorization','Bearer '.$this->tokens['access_token']);
        $this->apiCompany->update(['security_policy'=>['privileged_passkey_required'=>true]]);
        $this->postJson('/api/security/passkeys/register/options')->assertForbidden()->assertJsonPath('code','PASSKEY_REQUIRED');
        $this->putJson('/api/security/policy',['session_hours'=>24,'privileged_passkey_required'=>false,'finance_passkey_required'=>false,'payroll_passkey_required'=>false])->assertForbidden()->assertJsonPath('code','PASSKEY_REQUIRED');
        $this->apiCompany->update(['security_policy'=>['privileged_passkey_required'=>false]]);
        $this->apiCompany->update(['security_policy'=>['finance_passkey_required'=>true]]);
        $this->postJson('/api/security/passkeys/register/options')->assertForbidden()->assertJsonPath('code','PASSKEY_REQUIRED');
        $this->putJson('/api/security/policy',['session_hours'=>24,'privileged_passkey_required'=>false,'finance_passkey_required'=>false,'payroll_passkey_required'=>false])->assertForbidden()->assertJsonPath('code','PASSKEY_REQUIRED');
        $this->apiCompany->update(['security_policy'=>['finance_passkey_required'=>false]]);
        $counter=$this->postJson('/api/passkeys/login/options')->assertOk()->json();$this->postJson('/api/passkeys/login/verify',$this->assertion($counter,$key,$id,1))->assertUnprocessable();
        $foreign=User::factory()->create(['company_id'=>Company::factory()->create()->id,'role'=>'admin','email_verified_at'=>now()]);
        $this->withHeader('Authorization','Bearer '.app(JwtService::class)->createAccessToken($foreign));
        $step=$this->postJson('/api/security/passkeys/step_up/options')->assertOk()->json();
        $this->postJson('/api/security/passkeys/step_up/verify',$this->assertion($step,$key,$id,2))->assertUnprocessable();
        $this->deleteJson('/api/security/passkeys/'.$credential)->assertNotFound();
        $this->withHeader('Authorization','Bearer '.$tokens['access_token'])->deleteJson('/api/security/passkeys/'.$credential)->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
    }
    public function test_session_revocation_rotation_disabled_users_and_recent_authentication(): void
    {
        $second=app(JwtService::class)->issueTokens($this->owner);
        $sessions=$this->getJson('/api/security')->assertOk()->json('sessions');$other=collect($sessions)->firstWhere('current',false);
        $this->deleteJson('/api/security/sessions/'.$other['id'])->assertOk();
        $this->withHeader('Authorization','Bearer '.$second['access_token'])->getJson('/api/me')->assertUnauthorized();
        $this->assertNull(app(JwtService::class)->refresh($second['refresh_token']));
        $rotated=app(JwtService::class)->refresh($this->tokens['refresh_token']);$this->assertNotNull($rotated);
        $this->assertNull(app(JwtService::class)->refresh($this->tokens['refresh_token']));
        $this->withHeader('Authorization','Bearer '.$rotated['access_token']);
        DB::table('auth_sessions')->where('user_id',$this->owner->id)->update(['authenticated_at'=>now()->subHour()]);
        $this->postJson('/api/platform/service-accounts',['name'=>'Needs recent auth','scopes'=>['inventory.read']])->assertForbidden()->assertJsonPath('code','RECENT_AUTH_REQUIRED');
        $this->postJson('/api/security/confirm',['password'=>'password'])->assertOk();
        $this->postJson('/api/platform/service-accounts',['name'=>'Recent approved read','scopes'=>['inventory.read']])->assertCreated();
        $this->owner->update(['is_active'=>false]);
        $this->getJson('/api/me')->assertUnauthorized();$this->assertNull(app(JwtService::class)->refresh($rotated['refresh_token']));
    }
    public function test_entitlements_and_permissions_are_both_required_and_company_scoped(): void
    {
        $this->putJson('/api/commercial/capabilities',['package'=>'core-accounting','capabilities'=>['core','inventory','accounting']])->assertOk();
        $this->getJson('/api/payroll')->assertForbidden()->assertJsonPath('code','CAPABILITY_NOT_ENABLED');
        $me=$this->getJson('/api/me')->assertOk()->json();$this->assertNotContains('payroll.view',$me['permissions']);
        $this->putJson('/api/commercial/capabilities',['package'=>'platform','capabilities'=>['core','inventory','accounting','payroll']])->assertOk();
        $this->getJson('/api/payroll')->assertOk();
        $staff=User::factory()->create(['company_id'=>$this->apiCompany->id,'role'=>'staff','email_verified_at'=>now()]);
        $this->withHeader('Authorization','Bearer '.app(JwtService::class)->createAccessToken($staff))->getJson('/api/payroll')->assertForbidden();
        $this->putJson('/api/security/policy',['session_hours'=>24,'privileged_passkey_required'=>false,'finance_passkey_required'=>false,'payroll_passkey_required'=>false])->assertForbidden();
        $this->getJson('/api/commercial')->assertForbidden();
        $this->assertNull(Company::factory()->create()->capability_settings);
    }
    private function signer(): array
    {
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);$public=openssl_pkey_get_details($key)['key'];
        $file=tempnam(sys_get_temp_dir(),'aims-dc8-public-');file_put_contents($file,$public);$this->temporary[]=$file;
        config(['security.license_public_key'=>$file,'security.update_public_key'=>$file]);return [$key,$file];
    }
    private function signed(array $payload,$key): array
    {
        $raw=json_encode(['schema'=>2,'product'=>'AIMS']+$payload);openssl_sign($raw,$sig,$key,OPENSSL_ALGO_SHA256);
        return ['payload'=>PasskeyService::encode($raw),'signature'=>base64_encode($sig)];
    }
    public function test_license_tampering_binding_offline_grace_expiry_and_clock_review(): void
    {
        [$key]=$this->signer();$payload=['kind'=>'license','license_id'=>'DC8-SYNTHETIC','company'=>$this->apiCompany->fresh()->public_id,'installation_id'=>app(InstallationIdentity::class)->id(),
            'package'=>'distribution','capabilities'=>['core','inventory','payroll'],'valid_from'=>now()->subDay()->toIso8601String(),'valid_until'=>now()->addDay()->toIso8601String(),'offline_until'=>now()->addDays(8)->toIso8601String()];
        $doc=$this->signed($payload,$key);$bad=$doc;$bad['payload'].='A';
        $this->postJson('/api/commercial/license',['document'=>$bad])->assertUnprocessable();
        $foreign=$payload;$foreign['company']=Company::factory()->create()->public_id;
        $this->postJson('/api/commercial/license',['document'=>$this->signed($foreign,$key)])->assertUnprocessable();
        $this->postJson('/api/commercial/license',['document'=>$doc])->assertOk()->assertJsonPath('status','active');
        $this->putJson('/api/commercial/capabilities',['package'=>'platform','capabilities'=>Entitlements::CAPABILITIES])->assertUnprocessable();
        $this->travel(2)->days();$this->assertSame('grace',app(Entitlements::class)->state($this->apiCompany->id)['status']);
        $this->travel(8)->days();$state=app(Entitlements::class)->state($this->apiCompany->id);$this->assertSame('expired',$state['status']);$this->assertFalse($state['write_allowed']);
        $this->tokens=app(JwtService::class)->issueTokens($this->owner);$this->withHeader('Authorization','Bearer '.$this->tokens['access_token']);
        $this->getJson('/api/payroll')->assertOk();$this->postJson('/api/payroll',[])->assertForbidden()->assertJsonPath('code','LICENSE_REVIEW_REQUIRED');
        $this->assertDatabaseHas('users',['id'=>$this->owner->id]);
        $this->travelBack();$this->assertSame('clock_review',app(Entitlements::class)->state($this->apiCompany->id)['status']);
    }
    public function test_signed_update_preflight_blocks_tampering_missing_backup_and_wrong_key(): void
    {
        [$key]=$this->signer();$doc=$this->signed(['kind'=>'update','version'=>'1.1.3','build_id'=>'dc8-test-build','commit'=>str_repeat('a',40),
            'migration_level'=>'2026_10_31_000003','minimum_php'=>'8.3.35','minimum_version'=>'1.1.2-rc.1','released_at'=>now()->toIso8601String(),
            'compatibility'=>['api'=>1],'tree_sha256'=>str_repeat('c',64),'artifacts'=>[['filename'=>'AIMS-1.1.3.zip','sha256'=>str_repeat('a',64)]]],$key);
        $bad=$doc;$bad['signature']=base64_encode(random_bytes(256));
        $this->postJson('/api/commercial/update-preflight',['document'=>$bad,'workers_stopped'=>true])->assertUnprocessable();
        $r=$this->postJson('/api/commercial/update-preflight',['document'=>$doc,'workers_stopped'=>true])->assertOk()->assertJsonPath('status','blocked')->assertJsonPath('migration_started',false)->json();
        $this->assertSame('blocked',collect($r['checks'])->firstWhere('key','backup')['status']);
        DB::table('installation_state')->where('key','key_check')->update(['value'=>'wrong-key-evidence']);
        $r=$this->postJson('/api/commercial/update-preflight',['document'=>$doc,'workers_stopped'=>true])->assertOk()->json();
        $this->assertSame('blocked',collect($r['checks'])->firstWhere('key','encryption_key')['status']);
        $this->assertSame(0,DB::table('update_checks')->where('migration_started',true)->count());
    }
    public function test_support_bundle_and_audit_are_sanitized_and_tenant_scoped(): void
    {
        $foreign=Company::factory()->create();DB::table('security_events')->insert(['company_id'=>$foreign->id,'event'=>'foreign-marker-must-not-leak','created_at'=>now()]);
        $this->owner->update(['api_token'=>hash('sha256','secret-token-must-not-leak')]);
        $bundle=$this->postJson('/api/commercial/support-bundle')->assertOk()->json();
        $raw=json_encode($bundle);foreach(['secret-token-must-not-leak',$this->owner->password,config('app.key'),'foreign-marker-must-not-leak'] as $secret) $this->assertStringNotContainsString($secret,$raw);
        $this->assertSame(['format','generated_at','version','runtime','migration_state','health','integrations','recent_errors','presence','policy'],array_keys($bundle));
        $this->getJson('/api/security/audit-export')->assertOk()->assertDontSee('foreign-marker-must-not-leak');
        $this->getJson('/api/security')->assertOk()->assertHeader('X-Content-Type-Options','nosniff')->assertHeader('Cache-Control','no-store, private');
    }
    public function test_company_role_customization_does_not_change_another_company_or_allow_manager_takeover(): void
    {
        $this->putJson('/api/security/roles/staff',['permissions'=>['dashboard.view']])->assertOk();
        $staff=User::factory()->create(['company_id'=>$this->apiCompany->id,'role'=>'staff','email_verified_at'=>now()]);
        $this->withHeader('Authorization','Bearer '.app(JwtService::class)->createAccessToken($staff));
        $this->getJson('/api/me')->assertOk()->assertJsonPath('permissions',['dashboard.view']);
        $this->getJson('/api/inventory/health')->assertForbidden();
        $this->withHeader('Authorization','Bearer '.$this->tokens['access_token']);
        $this->putJson('/api/security/roles/manager',['permissions'=>['security.manage']])->assertUnprocessable();
        $foreign=User::factory()->create(['company_id'=>Company::factory()->create()->id,'role'=>'staff','email_verified_at'=>now()]);
        $this->withHeader('Authorization','Bearer '.app(JwtService::class)->createAccessToken($foreign));
        $this->assertContains('inventory.view',$this->getJson('/api/me')->assertOk()->json('permissions'));
        $this->getJson('/api/commercial')->assertForbidden();
    }
    public function test_owner_recovery_requires_escrowed_key_maintenance_and_preserves_evidence(): void
    {
        $oldStorage=app()->storagePath();$root=base_path('../output/dc8-recovery-test-'.Str::uuid());
        mkdir($root.'/framework',0700,true);app()->useStoragePath($root);
        $secret=bin2hex(random_bytes(32));$file=$root.'/owner-recovery-key';file_put_contents($file,'incorrect-recovery-secret-value-123456');$this->temporary[]=$file;
        config(['security.recovery_key_hash'=>\Illuminate\Support\Facades\Hash::make($secret)]);
        $options=['--company'=>$this->apiCompany->id,'--user'=>$this->owner->id,'--key-file'=>$file,'--reason'=>'Synthetic owner verified through the protected recovery process'];
        try {
            $this->assertSame(1,\Illuminate\Support\Facades\Artisan::call('aims:recover-identity',$options));
            \Illuminate\Support\Facades\Artisan::call('down');
            $this->assertSame(1,\Illuminate\Support\Facades\Artisan::call('aims:recover-identity',$options));
            $this->apiCompany->update(['security_policy'=>['privileged_passkey_required'=>true]]);
            file_put_contents($file,$secret);
            $this->assertSame(0,\Illuminate\Support\Facades\Artisan::call('aims:recover-identity',$options));
            $this->assertFalse($this->apiCompany->fresh()->security_policy['privileged_passkey_required']);
            $this->assertSame(0,DB::table('auth_sessions')->where('user_id',$this->owner->id)->whereNull('revoked_at')->count());
            $this->assertDatabaseHas('security_events',['company_id'=>$this->apiCompany->id,'event'=>'identity.owner_recovery']);
            $this->assertDatabaseHas('audit_logs',['company_id'=>$this->apiCompany->id,'action'=>'security.owner_recovery']);
            $this->assertNull(app(JwtService::class)->refresh($this->tokens['refresh_token']));
        } finally {\Illuminate\Support\Facades\Artisan::call('up');app()->useStoragePath($oldStorage);}
    }
}
