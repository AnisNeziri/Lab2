<?php
namespace Tests\Feature;
use App\Models\Company;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\SystemIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
class DC12ReferenceReviewTest extends TestCase
{
    use RefreshDatabase;
    public function test_declared_composite_keys_use_match_simple_and_keep_soft_deleted_parents(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This fixture inserts an orphan inside a SQLite deferred foreign-key transaction.');
        }
        Schema::create('dc12_parent',function(Blueprint $t){$t->integer('a');$t->integer('b');$t->softDeletes();$t->primary(['a','b']);});
        Schema::create('dc12_child',function(Blueprint $t){$t->id();$t->integer('a')->nullable();$t->integer('b')->nullable();$t->foreign(['a','b'])->references(['a','b'])->on('dc12_parent')->restrictOnDelete();});
        DB::table('dc12_parent')->insert(['a'=>1,'b'=>1,'deleted_at'=>now()]);
        DB::statement('PRAGMA defer_foreign_keys=ON');
        DB::table('dc12_child')->insert([['id'=>1,'a'=>1,'b'=>1],['id'=>2,'a'=>99,'b'=>null],['id'=>3,'a'=>99,'b'=>99]]);
        $audit=app(\App\Services\InstallationBackupService::class)->referenceAudit(true);
        $row=collect($audit['violations'])->firstWhere('table','dc12_child');
        $this->assertSame(1,$row['affected_count']);$this->assertSame(['a','b'],$row['columns']);$this->assertSame(['a','b'],$row['parent_columns']);$this->assertSame(3,$row['records'][0]['id']);$this->assertFalse($audit['healthy']);
    }
    public function test_reference_review_http_enforces_the_company_and_platform_boundaries(): void
    {
        $endpoint = '/api/system-integrity/references';
        $this->getJson($endpoint)->assertUnauthorized();

        $this->actingAsApiUser('admin');
        $this->apiCompany->update(['role_permissions' => ['admin' => ['system_integrity.view', 'deployment.view']]]);
        $this->getJson('/api/me')->assertOk();
        $permissions = app(PermissionService::class);
        $this->assertTrue($permissions->roleHasPermission('admin', 'system_integrity.view'));
        $this->assertFalse($permissions->roleHasPermission('admin', 'deployment.view'));
        $this->getJson($endpoint)->assertForbidden();
        $this->getJson('/api/superadmin/dashboard')->assertForbidden();

        $this->actingAsApiUser('manager');
        $this->getJson($endpoint)->assertForbidden();

        $this->actingAsApiUser('superadmin');
        $this->getJson('/api/superadmin/dashboard')->assertOk();
        $this->assertTrue($permissions->roleHasPermission('superadmin', 'deployment.view'));
        $this->getJson($endpoint)->assertForbidden()->assertJsonPath(
            'message', 'An authorized company account is required to access company data.'
        );
    }

    public function test_reference_projection_is_scoped_read_only_and_rejects_wrong_installation_evidence(): void
    {
        $company = Company::factory()->create();
        $foreign = Company::factory()->create();
        $foreignUser = User::factory()->create(['company_id' => $foreign->id]);
        $originalStorage = app()->storagePath();
        $storage = sys_get_temp_dir().'/aims-dc12-'.bin2hex(random_bytes(12));
        File::makeDirectory($storage.'/framework', 0700, true);
        $service = new class extends SystemIntegrityService
        {
            public function project(int $company): array
            {
                return $this->referenceReviewForCompany($company);
            }
        };

        try {
            app()->useStoragePath($storage);
            $auditLog = [
                'table' => 'audit_logs', 'constraint' => 'fixture_user_fk', 'columns' => ['user_id'],
                'parent' => 'users', 'parent_columns' => ['id'], 'nullable' => [true],
                'primary_columns' => ['id'], 'affected_count' => 2,
                'records' => [
                    ['id' => 111, 'company_id' => $company->id, 'user_id' => 999, 'amount' => '555.00'],
                    ['id' => 222, 'company_id' => $foreign->id, 'user_id' => 998, 'amount' => '999.00'],
                ],
            ];
            $userRole = array_replace($auditLog, [
                'table' => 'user_roles', 'constraint' => 'fixture_other_user', 'affected_count' => 1,
                'records' => [['id' => 333, 'user_id' => $foreignUser->id]],
            ]);
            $service->recordReferenceAudit([
                'checked_at' => now()->toIso8601String(), 'violated_constraints' => 2,
                'constraints_checked' => 707, 'violations' => [$auditLog, $userRole],
            ]);
            $file = $storage.'/framework/aims-reference-review.json';
            $report = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
            $report['user_ownership'] = [$foreignUser->id => $company->id];
            $stored = json_encode($report, JSON_THROW_ON_ERROR);
            File::put($file, $stored);

            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $local = $service->project($company->id);
                $other = $service->project($foreign->id);
                $queries = array_column(DB::getQueryLog(), 'query');
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $this->assertNotEmpty($queries);
            foreach ($queries as $query) {
                $this->assertMatchesRegularExpression('/^select\b/i', $query);
            }
            $this->assertSame($stored, File::get($file));
            $this->assertSame('REVIEW_REQUIRED', $local['status']);
            $this->assertFalse($local['migration_ready']);
            $this->assertSame([['id' => 111, 'company_id' => $company->id, 'user_id' => 999]], $local['rows'][0]['records']);
            $this->assertSame(1, $local['rows'][0]['restricted_record_count']);
            $this->assertSame([], $local['rows'][1]['records']);
            $this->assertSame(1, $local['rows'][1]['restricted_record_count']);
            $this->assertSame([['id' => 222, 'company_id' => $foreign->id, 'user_id' => 998]], $other['rows'][0]['records']);
            $this->assertSame([['id' => 333, 'user_id' => $foreignUser->id]], $other['rows'][1]['records']);
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertDatabaseCount('accounting_exceptions', 0);

            foreach (['app_key_fingerprint', 'source_scope'] as $field) {
                File::put($file, json_encode(array_replace($report, [$field => 'wrong']), JSON_THROW_ON_ERROR));
                $unverified = $service->project($company->id);
                $this->assertSame('NOT_VERIFIED', $unverified['status']);
                $this->assertFalse($unverified['migration_ready']);
                $this->assertSame([], $unverified['rows']);
            }
        } finally {
            app()->useStoragePath($originalStorage);
            File::deleteDirectory($storage);
        }
    }
}
