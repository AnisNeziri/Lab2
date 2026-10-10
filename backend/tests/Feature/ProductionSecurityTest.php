<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{AuthService, JwtService, PasswordResetService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash};
use Tests\TestCase;

class ProductionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_revokes_old_access_refresh_and_legacy_tokens_and_cannot_replay(): void
    {
        $user = User::factory()->create(['email_verified_at'=>now(), 'api_token'=>hash('sha256','legacy-private')]);
        $tokens = app(JwtService::class)->issueTokens($user);
        $this->withHeader('Authorization','Bearer '.$tokens['access_token'])->getJson('/api/me')->assertOk();
        DB::table('password_reset_tokens')->insert(['email'=>$user->email,'token'=>hash('sha256','reset-once'),'created_at'=>now()]);
        $service=app(PasswordResetService::class);
        $this->assertNotNull($service->reset($user->email,'reset-once','Changed-Password-123!'));
        $this->assertNull($service->reset($user->email,'reset-once','Other-Password-123!'));
        $this->withHeader('Authorization','Bearer '.$tokens['access_token'])->getJson('/api/me')->assertUnauthorized();
        $this->withHeader('Authorization','Bearer legacy-private')->getJson('/api/me')->assertUnauthorized();
        $this->assertNull(app(JwtService::class)->refresh($tokens['refresh_token']));
        $this->assertTrue(Hash::check('Changed-Password-123!',$user->fresh()->password));
    }

    public function test_reset_expiry_and_future_clock_fail_closed(): void
    {
        $user=User::factory()->create(); $before=$user->password;
        foreach([now()->subMinutes(61),now()->addHours(1)] as $created) {
            DB::table('password_reset_tokens')->updateOrInsert(['email'=>$user->email],['token'=>hash('sha256','reset'),'created_at'=>$created]);
            $this->assertNull(app(PasswordResetService::class)->reset($user->email,'reset','Changed-Password-123!'));
            $this->assertSame($before,$user->fresh()->password);
        }
    }

    public function test_logout_password_change_and_refresh_rotation_revoke_previous_tokens(): void
    {
        $user=User::factory()->create(['email_verified_at'=>now()]);
        $jwt=app(JwtService::class); $tokens=$jwt->issueTokens($user);
        $rotated=$jwt->refresh($tokens['refresh_token']);
        $this->assertNotNull($rotated); $this->assertNull($jwt->refresh($tokens['refresh_token']));
        app(AuthService::class)->changePassword($user,['password'=>'Changed-Password-123!']);
        $this->withHeader('Authorization','Bearer '.$rotated['access_token'])->getJson('/api/me')->assertUnauthorized();
        $this->assertNull($jwt->refresh($rotated['refresh_token']));
        $current=$jwt->issueTokens($user->fresh());
        $this->withHeader('Authorization','Bearer '.$current['access_token'])->postJson('/api/logout')->assertOk();
        $this->withHeader('Authorization','Bearer '.$current['access_token'])->getJson('/api/me')->assertUnauthorized();
    }

    public function test_unknown_issuer_algorithm_and_future_issue_time_are_rejected_even_when_signed(): void
    {
        $jwt=app(JwtService::class); $user=User::factory()->create();
        $encode=fn($v)=>rtrim(strtr(base64_encode(json_encode($v)),'+/','-_'),'=');
        foreach([['iss'=>'https://other.invalid'],['iat'=>time()+100],['alg'=>'none']] as $change) {
            $header=$encode(['alg'=>$change['alg']??'HS256','typ'=>'JWT']);
            $payload=$encode(['sub'=>$user->id,'ver'=>0,'iss'=>$change['iss']??config('app.url'),'iat'=>$change['iat']??time(),'exp'=>time()+1000]);
            $signature=rtrim(strtr(base64_encode(hash_hmac('sha256',"$header.$payload",config('jwt.secret'),true)),'+/','-_'),'=');
            $this->assertNull($jwt->validateAccessToken("$header.$payload.$signature"));
        }
    }

    public function test_production_logs_redact_config_secrets_sql_bindings_and_exception_content(): void
    {
        config(['database.connections.mysql.password'=>'unique-db-private-secret']);
        $handler=new \Monolog\Handler\TestHandler(); $logger=new \Monolog\Logger('certification',[$handler]);
        (new \App\Logging\SafeLogTap())($logger);
        $logger->error('Connection password=visible-private unique-db-private-secret',['exception'=>new \RuntimeException('private document bytes'),'bindings'=>['private customer data'],'token'=>'secret-token']);
        $logger->warning('Broadcast URL http://127.0.0.1/events?auth_key=provider-key&auth_signature=provider-signature&auth_timestamp=123');
        $serialized=json_encode($handler->getRecords());
        foreach(['visible-private','unique-db-private-secret','private document bytes','private customer data','secret-token','provider-key','provider-signature'] as $secret)$this->assertStringNotContainsString($secret,$serialized);
    }

    public function test_mariadb_has_no_implicitly_mutating_timestamp_columns(): void
    {
        if(DB::connection()->getDriverName()!=='mysql') { $this->assertTrue(true); return; }
        $this->assertSame([],DB::select("select TABLE_NAME, COLUMN_NAME from information_schema.COLUMNS where TABLE_SCHEMA=DATABASE() and DATA_TYPE='timestamp' and lower(EXTRA) like '%on update current_timestamp%'"));
        $this->assertSame('+00:00',DB::selectOne('select @@session.time_zone as timezone')->timezone);
    }

    public function test_fresh_request_authenticates_tenant_before_route_model_binding(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $a=\App\Models\Company::factory()->create(); $b=\App\Models\Company::factory()->create();
        $actor=User::factory()->create(['company_id'=>$b->id,'role'=>'admin','email_verified_at'=>now()]);
        $category=\App\Models\Category::create(['company_id'=>$a->id,'name'=>'Private category']);
        $product=\App\Models\Product::create(['company_id'=>$a->id,'category_id'=>$category->id,'name'=>'Private A product','sku'=>'PR1-PRIVATE','unit'=>'pcs','quantity'=>0,'price'=>2,'purchase_price'=>1,'selling_price'=>2]);
        \Illuminate\Support\Facades\Auth::logout();
        $token=app(JwtService::class)->createAccessToken($actor);
        $this->withHeader('Authorization','Bearer '.$token)->getJson('/api/products/'.$product->id)->assertNotFound();
        $this->withHeader('Authorization','Bearer '.$token)->putJson('/api/products/'.$product->id,['name'=>'Intrusion'])->assertNotFound();
        $this->assertSame('Private A product',$product->fresh()->name);
    }
}
