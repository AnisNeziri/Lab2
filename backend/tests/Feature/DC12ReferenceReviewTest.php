<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema,Auth};
class DC12ReferenceReviewTest extends TestCase
{
    use RefreshDatabase;
    public function test_declared_composite_keys_use_match_simple_and_keep_soft_deleted_parents(): void
    {
        Schema::create('dc12_parent',function(Blueprint $t){$t->integer('a');$t->integer('b');$t->softDeletes();$t->primary(['a','b']);});
        Schema::create('dc12_child',function(Blueprint $t){$t->id();$t->integer('a')->nullable();$t->integer('b')->nullable();$t->foreign(['a','b'])->references(['a','b'])->on('dc12_parent')->restrictOnDelete();});
        DB::table('dc12_parent')->insert(['a'=>1,'b'=>1,'deleted_at'=>now()]);
        DB::statement('PRAGMA defer_foreign_keys=ON');
        DB::table('dc12_child')->insert([['id'=>1,'a'=>1,'b'=>1],['id'=>2,'a'=>99,'b'=>null],['id'=>3,'a'=>99,'b'=>99]]);
        $audit=app(\App\Services\InstallationBackupService::class)->referenceAudit(true);
        $row=collect($audit['violations'])->firstWhere('table','dc12_child');
        $this->assertSame(1,$row['affected_count']);$this->assertSame(['a','b'],$row['columns']);$this->assertSame(['a','b'],$row['parent_columns']);$this->assertSame(3,$row['records'][0]['id']);$this->assertFalse($audit['healthy']);
    }
    public function test_reference_review_is_scoped_read_only_and_rejects_wrong_installation_evidence(): void
    {
        $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$company=Auth::user()->company_id;$original=app()->storagePath();$temp=base_path('../output/dc12-view-test-'.bin2hex(random_bytes(5)));mkdir($temp.'/framework',0700,true);
        try {
            app()->useStoragePath($temp);$c=config('database.connections.'.config('database.default'));$scope=hash('sha256',DB::getDriverName().':'.($c['host']??'').':'.($c['port']??'').':'.($c['database']??''));
            $v=['table'=>'audit_logs','constraint'=>'fixture_user_fk','columns'=>['user_id'],'parent'=>'users','parent_columns'=>['id'],'nullable'=>[true],'primary_columns'=>['id'],'affected_count'=>2,'records'=>[['id'=>111,'company_id'=>$company,'user_id'=>999,'amount'=>'555.00'],['id'=>222,'company_id'=>$company+500,'user_id'=>998,'amount'=>'999.00']]];
            $foreign=\App\Models\Company::factory()->create();$foreignUser=\App\Models\User::factory()->create(['company_id'=>$foreign->id]);
            $other=$v;$other['table']='user_roles';$other['constraint']='fixture_other_user';$other['affected_count']=1;$other['records']=[['id'=>333,'user_id'=>$foreignUser->id]];
            $report=['source_scope'=>$scope,'app_key_fingerprint'=>hash('sha256',config('app.key')),'user_ownership'=>[(string)$foreignUser->id=>$company],'baseline'=>['checked_at'=>now()->toIso8601String(),'violated_constraints'=>2,'constraints_checked'=>707,'violations'=>[$v,$other]],'findings'=>[]];$file=$temp.'/framework/aims-reference-review.json';file_put_contents($file,json_encode($report));
            $this->getJson('/api/system-integrity/references')->assertOk()->assertJsonPath('migration_ready',false)->assertJsonCount(1,'rows.0.records')->assertJsonPath('rows.0.records.0.id',111)->assertJsonPath('rows.0.restricted_record_count',1)->assertDontSee('555.00')->assertDontSee('999.00')->assertDontSee('222');
            $this->getJson('/api/system-integrity/references')->assertOk()->assertJsonCount(0,'rows.1.records')->assertJsonPath('rows.1.restricted_record_count',1)->assertDontSee('333');
            $this->assertDatabaseCount('journal_entries',0);$this->assertDatabaseCount('accounting_exceptions',0);
            $report['app_key_fingerprint']='wrong';file_put_contents($file,json_encode($report));$this->getJson('/api/system-integrity/references')->assertOk()->assertJsonPath('status','NOT_VERIFIED')->assertJsonCount(0,'rows');
            $this->actingAsApiUser('manager');$this->getJson('/api/system-integrity/references')->assertForbidden();
        } finally {app()->useStoragePath($original);}
    }
}
