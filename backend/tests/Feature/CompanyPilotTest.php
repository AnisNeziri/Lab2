<?php

namespace Tests\Feature;

use App\Http\Middleware\ProductionConfiguration;
use App\Models\Company;
use App\Models\DailySale;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PilotEvidence;
use App\Models\PilotFeedback;
use App\Models\PilotProfile;
use App\Models\PilotReport;
use App\Models\Product;
use App\Models\User;
use App\Services\PilotService;
use App\Services\PilotTelemetryService;
use App\Services\PortableBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanyPilotTest extends TestCase
{
    use RefreshDatabase;

    private function current(): User
    {
        $user = User::where('company_id', $this->apiCompany->id)->firstOrFail();
        Auth::setUser($user);

        return $user;
    }

    private function plan(array $extra = []): array
    {
        return array_replace(['revision' => 0, 'status' => 'draft', 'cutover_date' => '2026-10-05', 'timezone' => 'Europe/Budapest', 'business_days' => [1, 2, 3, 4, 5], 'workstreams' => ['inventory'], 'participants' => [['user_id' => $this->current()->id, 'responsibility' => 'owner', 'tasks' => 'Check stock and approved count']], 'telemetry_enabled' => false], $extra);
    }

    private function document(): Document
    {
        $type = DocumentType::create(['name' => 'Source']);

        return Document::create(['document_type_id' => $type->id, 'title' => 'Source reconciliation', 'uuid' => (string) Str::uuid(), 'created_by' => Auth::id()]);
    }

    private function evidence(array $extra = []): array
    {
        return array_replace(['kind' => 'workflow_timing', 'observed_on' => '2026-10-05', 'outcome' => 'confirmed', 'checks' => [['name' => 'Order processing timing observed', 'result' => 'confirmed']]], $extra);
    }

    private function switchCompany(): User
    {
        $this->apiCompany = Company::factory()->create();
        $token = (string) Str::uuid();
        $user = User::factory()->create(['company_id' => $this->apiCompany->id, 'role' => 'admin', 'email_verified_at' => now(), 'api_token' => hash('sha256', $token)]);
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token);

        return $user;
    }

    public function test_owner_scorecard_is_private_and_staff_cannot_read_other_roles_or_finance(): void
    {
        $this->getJson('/api/pilot')->assertUnauthorized();
        $this->actingAsApiUser('staff')->getJson('/api/pilot')->assertForbidden();
        $this->getJson('/api/pilot/feedback')->assertForbidden();
        $this->getJson('/api/pilot/context')->assertOk()->assertJsonPath('telemetry_enabled', false);
        $this->actingAsApiUser()->getJson('/api/pilot')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('readiness.exit.real_pilot_completed', false)->assertJsonPath('readiness.forecast.models.state', 'INSUFFICIENT');
    }

    public function test_unknown_sales_days_are_not_zero_and_no_calendar_is_unknown(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00'));
        $this->actingAsApiUser();
        $this->getJson('/api/pilot')->assertOk()->assertJsonPath('readiness.sales.business_days_with_finalized_sales.percent', null);
        $this->putJson('/api/pilot/plan', $this->plan())->assertOk()->assertJsonPath('revision', 1);
        DailySale::create(['sale_number' => 'REAL-SOURCE-1', 'sale_date' => '2026-10-06', 'status' => 'finalized']);
        DB::table('daily_sales_days')->insert(['company_id' => $this->apiCompany->id, 'sale_date' => '2026-10-05']);
        $response = $this->getJson('/api/pilot')->assertOk()->assertJsonPath('readiness.sales.business_days_with_finalized_sales.count', 1)->assertJsonPath('readiness.sales.business_days_with_finalized_sales.total', 3)->assertJsonPath('readiness.sales.business_days_with_finalized_sales.percent', 33.3)->assertJsonPath('readiness.sales.missing_days_are_zero', false);
        $response->assertJsonPath('readiness.period.elapsed_days', 3);
        $this->assertSame(['2026-10-05', '2026-10-07'], $response->json('readiness.sales.unknown_days'));
        $this->assertDatabaseCount('analytics_snapshots', 0); // A view must not manufacture learning history.
    }

    public function test_plan_revision_and_foreign_tenant_participants_are_checked(): void
    {
        $this->actingAsApiUser();
        $plan = $this->plan();
        $this->putJson('/api/pilot/plan', $plan)->assertOk();
        $this->putJson('/api/pilot/plan', $plan)->assertConflict();
        $foreign = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->putJson('/api/pilot/plan', array_replace($plan, ['revision' => 1, 'participants' => [['user_id' => $foreign->id, 'responsibility' => 'sales', 'tasks' => 'Create order']]]))->assertUnprocessable();
        $this->assertDatabaseCount('pilot_profiles', 1);
    }

    public function test_test_database_cannot_be_attested_or_activated_and_profiles_default_off(): void
    {
        $this->actingAsApiUser();
        $plan = $this->plan();
        // DDL inside RefreshDatabase would implicitly commit MariaDB's test transaction.
        $schema = Schema::getFacadeRoot();
        Schema::partialMock()->shouldReceive('hasTable')->andReturnUsing(fn ($table) => $table === '_aims_performance_manifest' || $schema->hasTable($table));
        $this->getJson('/api/pilot')->assertOk()->assertJsonPath('readiness.source.state', 'SYNTHETIC_OR_DEMO');
        $this->putJson('/api/pilot/plan', array_replace($plan, ['attest_real_data' => true, 'source_document_ids' => [$this->document()->id]]))->assertUnprocessable();
        $this->putJson('/api/pilot/plan', array_replace($plan, ['status' => 'active']))->assertUnprocessable();
        $this->assertDatabaseCount('pilot_profiles', 0);
    }

    public function test_feedback_strips_query_and_secrets_and_cannot_link_other_company(): void
    {
        $this->actingAsApiUser('staff');
        $user = $this->current();
        $this->postJson('/api/pilot/feedback', ['page' => '/products?token=secret-value&customer=Private', 'category' => 'error', 'description' => 'password=private-pass Bearer abcdef123456 someone@example.com', 'role' => 'admin', 'company_id' => 999])->assertCreated();
        $feedback = PilotFeedback::firstOrFail();
        $this->assertSame('/products', $feedback->page);
        $this->assertSame('staff', $feedback->role);
        $this->assertSame($user->company_id, $feedback->company_id);
        foreach (['private-pass', 'abcdef123456', 'someone@example.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $feedback->description);
        }
        $foreign = Product::create(['company_id' => Company::factory()->create()->id, 'name' => 'Foreign', 'sku' => 'FOREIGN', 'unit' => 'pcs', 'price' => 10]);
        // The company trait overrides supplied IDs; insert directly to build a real foreign fixture.
        DB::table('products')->where('id', $foreign->id)->update(['company_id' => Company::factory()->create()->id]);
        $this->postJson('/api/pilot/feedback', ['page' => '/products', 'category' => 'error', 'entity_type' => 'product', 'entity_id' => $foreign->id])->assertStatus(404);
    }

    public function test_failed_checks_cannot_be_confirmed_and_source_is_required(): void
    {
        $this->actingAsApiUser();
        $this->current();
        $this->postJson('/api/pilot/evidence', $this->evidence(['kind' => 'opening_finance']))->assertUnprocessable();
        $this->postJson('/api/pilot/evidence', $this->evidence(['checks' => [['name' => 'Unreconciled', 'result' => 'failed']]]))->assertUnprocessable();
        $this->assertDatabaseCount('pilot_evidence', 0);
    }

    public function test_finance_evidence_requires_independent_authorized_review_and_is_immutable(): void
    {
        $this->actingAsApiUser();
        $owner = $this->current();
        $doc = $this->document();
        $row = $this->postJson('/api/pilot/evidence', $this->evidence(['kind' => 'opening_finance', 'document_id' => $doc->id]))->assertCreated()->json();
        $this->postJson('/api/pilot/evidence/'.$row['id'].'/review')->assertUnprocessable();
        $reviewer = User::factory()->create(['company_id' => $owner->company_id, 'role' => 'manager', 'api_token' => hash('sha256', 'finance-reviewer'), 'email_verified_at' => now()]);
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer finance-reviewer');
        $this->postJson('/api/pilot/evidence/'.$row['id'].'/review')->assertOk()->assertJsonPath('reviewed_by', $reviewer->id);
        $this->assertTrue(app(PilotService::class)->confirmed('opening_finance'));
        $this->expectException(\LogicException::class);
        PilotEvidence::find($row['id'])->update(['outcome' => 'failed']);
    }

    public function test_other_company_cannot_read_or_review_evidence_or_reports(): void
    {
        $this->actingAsApiUser();
        $this->current();
        $id = $this->postJson('/api/pilot/evidence', $this->evidence())->assertCreated()->json('id');
        $report = $this->postJson('/api/pilot/reports', ['kind' => 'weekly'])->assertCreated()->json('id');
        $this->switchCompany();
        $this->postJson('/api/pilot/evidence/'.$id.'/review')->assertNotFound();
        $this->getJson('/api/pilot/reports/'.$report)->assertNotFound();
        $this->getJson('/api/pilot/evidence')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_csv_preflight_accounts_for_every_row_and_never_merges_or_imports(): void
    {
        $this->actingAsApiUser();
        $this->current();
        Product::create(['sku' => 'REAL-01', 'name' => 'Widget', 'unit' => 'pcs', 'price' => 10]);
        $csv = "sku,name,unit\nREAL01,Widget,pcs\nNEW,Other,\nNEW,Other,pcs\n";
        $response = $this->post('/api/pilot/imports/preflight', ['dataset' => 'products', 'file' => UploadedFile::fake()->createWithContent('products.csv', $csv)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('summary.total_rows', 3)->assertJsonPath('summary.accepted_rows', 2)->assertJsonPath('summary.rejected_rows', 1)->assertJsonPath('summary.duplicate_candidates', 2)->assertJsonPath('summary.missing_required_fields', 1)->assertJsonPath('imported_rows', 0);
        $this->assertSame(hash('sha256', $csv), $response->json('sha256'));
        $this->assertDatabaseCount('products', 1);
    }

    public function test_snapshot_is_idempotent_and_append_only(): void
    {
        $this->actingAsApiUser();
        $this->freezeTime();
        $one = $this->postJson('/api/pilot/reports', ['kind' => 'weekly'])->assertCreated()->json('id');
        $this->postJson('/api/pilot/reports', ['kind' => 'weekly'])->assertCreated()->assertJsonPath('id', $one);
        $this->expectException(\LogicException::class);
        PilotReport::find($one)->delete();
    }

    public function test_no_opt_in_produces_no_usage_rows_and_small_sample_percentiles_are_unknown(): void
    {
        $this->actingAsApiUser();
        $this->current();
        $this->postJson('/api/pilot/visit')->assertNoContent();
        $this->assertDatabaseCount('pilot_daily_users', 0);
        DB::table('pilot_request_aggregates')->insert(['company_id' => $this->apiCompany->id, 'day' => today()->subDay(), 'route_key' => 'api/products', 'method' => 'GET', 'role' => 'staff', 'http_status' => 200, 'requests' => 5, 'under_100' => 5]);
        $row = app(PilotTelemetryService::class)->summary(today()->subDay()->toDateString(), today()->toDateString())['endpoints'][0];
        $this->assertNull($row['p50_upper_ms']);
        $this->assertNull($row['p95_upper_ms']);
    }

    public function test_opted_in_usage_is_aggregate_and_visible_navigation_is_deduplicated(): void
    {
        $this->actingAsApiUser();
        $user = $this->current();
        config(['pilot.company_id' => $user->company_id]);
        PilotProfile::create(['status' => 'active', 'timezone' => 'UTC', 'telemetry_enabled' => true, 'source_attestation' => ['declared_real' => true]]);
        $this->getJson('/api/products?search=PRIVATE_SEARCH_TERM')->assertOk();
        $aggregate = DB::table('pilot_request_aggregates')->where('company_id', $user->company_id)->first();
        $this->assertSame('api/products', $aggregate->route_key);
        $this->assertSame(1, (int) $aggregate->requests);
        $this->assertStringNotContainsString('PRIVATE_SEARCH_TERM', json_encode($aggregate));
        $this->postJson('/api/pilot/visit')->assertNoContent();
        $this->postJson('/api/pilot/visit')->assertNoContent();
        $this->assertDatabaseCount('pilot_daily_users', 1);
        PilotProfile::first()->update(['status' => 'paused']);
        Cache::forget('pilot-profile:'.$user->company_id);
        $this->getJson('/api/products')->assertOk();
        $this->assertSame(1, (int) DB::table('pilot_request_aggregates')->value('requests'));
    }

    public function test_report_percentiles_are_histogram_bounds_and_open_tail_stays_unknown(): void
    {
        $this->actingAsApiUser();
        $this->current();
        DB::table('pilot_request_aggregates')->insert(['company_id' => $this->apiCompany->id, 'day' => today()->subDay(), 'route_key' => 'api/products', 'method' => 'GET', 'role' => 'staff', 'http_status' => 200, 'requests' => 100, 'under_100' => 50, 'under_300' => 40, 'over_10000' => 10]);
        $row = app(PilotTelemetryService::class)->summary(today()->subDay()->toDateString(), today()->toDateString())['endpoints'][0];
        $this->assertSame(100, $row['p50_upper_ms']);
        $this->assertNull($row['p95_upper_ms']);
    }

    public function test_recent_failure_blocks_old_approval_and_draft_issues_can_be_resolved_without_deletion(): void
    {
        $this->actingAsApiUser();
        $user = $this->current();
        PilotEvidence::create(['kind' => 'permission_review', 'observed_on' => '2026-10-05', 'outcome' => 'confirmed', 'checks' => [['name' => 'Role tasks checked', 'result' => 'confirmed']], 'prepared_by' => $user->id, 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
        $this->assertTrue(app(PilotService::class)->confirmed('permission_review'));
        $this->postJson('/api/pilot/evidence', $this->evidence(['kind' => 'permission_review', 'observed_on' => '2026-10-06', 'outcome' => 'failed', 'checks' => [['name' => 'Finance role blocked', 'result' => 'failed']]]))->assertCreated();
        $this->assertFalse(app(PilotService::class)->confirmed('permission_review'));
        $id = $this->postJson('/api/pilot/feedback', ['page' => '/finance', 'category' => 'error'])->assertCreated()->json('id');
        $this->patchJson('/api/pilot/feedback/'.$id, ['priority' => 'P1', 'status' => 'resolved'])->assertUnprocessable();
        $this->patchJson('/api/pilot/feedback/'.$id, ['priority' => 'P1', 'status' => 'resolved', 'resolution' => 'Reviewed role task access'])->assertOk()->assertJsonPath('status', 'resolved');
        $this->assertDatabaseCount('pilot_feedback', 1);
    }

    public function test_portable_company_restore_archives_evidence_and_does_not_reenroll(): void
    {
        $this->actingAsApiUser();
        $owner = $this->current();
        $this->putJson('/api/pilot/plan', $this->plan())->assertOk();
        $this->postJson('/api/pilot/evidence', $this->evidence())->assertCreated();
        $this->postJson('/api/pilot/reports', ['kind' => 'baseline'])->assertCreated();
        $archive = app(PortableBackupService::class)->export(['company'])->getContent();
        $target = $this->switchCompany();
        $this->post('/api/backup/import', ['file' => UploadedFile::fake()->createWithContent('pilot.json', $archive), 'mode' => 'merge'], ['Accept' => 'application/json'])->assertOk();
        $profile = PilotProfile::firstOrFail();
        $this->assertSame('draft', $profile->status);
        $this->assertFalse($profile->telemetry_enabled);
        $this->assertNull($profile->source_attestation);
        $this->assertSame([], $profile->participants);
        $row = PilotEvidence::firstOrFail();
        $this->assertTrue((bool) $row->is_historical);
        $this->assertNull($row->reviewed_at);
        $this->postJson('/api/pilot/evidence/'.$row->id.'/review')->assertUnprocessable();
        $this->assertTrue((bool) PilotReport::firstOrFail()->is_historical);
    }

    public function test_existing_inventory_and_supplier_gates_are_read_without_qualifying_unverified_sources(): void
    {
        $this->actingAsApiUser();
        $this->current();
        $product = Product::create(['sku'=>'GATE-CHECK', 'name'=>'Gate fixture', 'unit'=>'pcs', 'price'=>1]);
        $supplier = \App\Models\Supplier::create(['name'=>'Gate supplier fixture']);
        $dataset = \App\Models\AnalyticsDataset::create(['version'=>(string)Str::uuid(),'name'=>'Test-only frozen history','date_from'=>'2026-06-01','date_to'=>'2026-10-01','feature_definitions'=>[],'quality_status'=>'test_only']);
        $artifact=['test_only'=>true];
        $base=['analytics_dataset_id'=>$dataset->id,'feature_version'=>'test-only','algorithm'=>'seasonal_mean','training_cutoff'=>'2026-10-01','artifact'=>$artifact,'artifact_hash'=>\App\Services\InventoryIntelligenceService::artifactHash($artifact),'review'=>['production_version'=>null,'unit'=>'pcs']];
        \App\Models\InventoryForecastModel::create($base+['version'=>(string)Str::uuid(),'product_id'=>$product->id,'horizon'=>7,'status'=>'active','metrics'=>['mae'=>1,'horizon_mae'=>1,'blocks'=>2,'observations'=>14], 'comparison'=>['seasonal_mean'=>['mae'=>2,'horizon_mae'=>2]], 'quality'=>['observed_days'=>100,'recent_observed_days'=>90,'recent_calendar_days'=>90]]);
        \App\Models\SupplierLeadModel::create($base+['version'=>(string)Str::uuid(),'supplier_id'=>$supplier->id,'horizon'=>0,'status'=>'candidate','metrics'=>['mae'=>1], 'comparison'=>['challenger'=>['mae'=>1,'samples'=>8],'baseline'=>['mae'=>2]], 'quality'=>['samples'=>40]]);
        $response=$this->getJson('/api/pilot')->assertOk()->assertJsonPath('readiness.forecast.models.rows.0.state','EVALUATING')->assertJsonPath('readiness.supplier.models.rows.0.state','EVALUATING');
        $this->assertSame(['real_data_unverified'],$response->json('readiness.forecast.models.rows.0.reasons'));
        $this->assertSame(['real_data_unverified'],$response->json('readiness.supplier.models.rows.0.reasons'));
        $this->assertDatabaseCount('analytics_snapshots',0);
        $this->assertDatabaseCount('inventory_model_decisions',0);
        $this->assertDatabaseCount('inventory_forecast_models',2);
    }

    public function test_production_api_errors_have_safe_correlated_reference(): void
    {
        $this->actingAsApiUser();
        $this->current();
        $this->withoutMiddleware(ProductionConfiguration::class);
        $this->app['env'] = 'production';
        config(['pilot.company_id' => $this->apiCompany->id]);
        Route::middleware('auth.token')->get('/api/pilot-error-test', function () {
            throw new \RuntimeException('password=private-error-secret token=also-secret');
        });
        $response = $this->getJson('/api/pilot-error-test')->assertStatus(500);
        $this->assertMatchesRegularExpression('/^AIMS-[a-f0-9]{16}$/',$response->json('reference'));
        $this->assertStringNotContainsString('private-error-secret',$response->getContent());
        $this->assertDatabaseHas('pilot_incidents',['reference' => $response->json('reference'), 'company_id' => $this->apiCompany->id, 'exception_type' => 'RuntimeException']);
        $this->assertStringNotContainsString('also-secret',json_encode(DB::table('pilot_incidents')->get()));
    }
}
