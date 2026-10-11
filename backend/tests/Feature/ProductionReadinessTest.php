<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackupRunService;
use App\Services\PortableBackupService;
use App\Services\ProductionReadinessService;
use App\Support\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsafe_production_configuration_fails_with_actions_without_secrets(): void
    {
        config(['app.debug' => true, 'app.key' => 'private-test-secret', 'database.connections.mysql.password' => 'db-private-secret', 'cors.allowed_origins' => ['*'], 'queue.connections.database.retry_after' => 90]);
        $checks = collect(app(ProductionReadinessService::class)->configuration())->keyBy('key');
        foreach (['environment', 'debug', 'app_key', 'queue_retry', 'cors'] as $key) $this->assertSame('critical', $checks[$key]['status']);
        $json = $checks->toJson();
        $this->assertStringNotContainsString('private-test-secret', $json);
        $this->assertStringNotContainsString('db-private-secret', $json);
        $this->assertStringContainsString('APP_DEBUG=false', $json);
    }

    public function test_future_backup_rejected_before_business_changes(): void
    {
        $this->actingAsApiUser();
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        $service = app(PortableBackupService::class);
        $archive = json_decode($service->export(['products'])->getContent(), true);
        $archive['manifest']['app_version'] = '99.0.0';
        unset($archive['checksum']);
        $archive['checksum'] = 'sha256:'.hash('sha256', json_encode($archive, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $before = DB::table('products')->count();
        try { $service->restore(\Illuminate\Http\UploadedFile::fake()->createWithContent('future.aimsbackup', json_encode($archive))); $this->fail('Future archive accepted'); }
        catch (\Illuminate\Validation\ValidationException $error) { $this->assertStringContainsString('incompatible', json_encode($error->errors())); }
        $this->assertSame($before, DB::table('products')->count());
    }

    public function test_backup_error_summaries_never_store_exception_secrets(): void
    {
        $this->actingAsApiUser();
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        $service = app(BackupRunService::class);
        $run = $service->start('export', 'full');
        $service->fail($run, new \RuntimeException('password=db-secret token=customer-token document=private-content'));
        $this->assertSame('failed', $run->fresh()->status);
        foreach (['db-secret', 'customer-token', 'private-content'] as $secret) $this->assertStringNotContainsString($secret, $run->fresh()->error_summary);
    }

    public function test_old_migration_never_resets_an_existing_administrators_password(): void
    {
        $user = User::factory()->create(['email' => 'admin@enterprise.com']);
        $before = $user->password;
        $migration = require database_path('migrations/2026_06_05_020000_reset_admin.php');
        $migration->up();
        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_diagnostics_require_an_authorized_company_admin(): void
    {
        $this->getJson('/api/system/diagnostics')->assertUnauthorized();
        $this->actingAsApiUser('staff')->getJson('/api/system/diagnostics')->assertForbidden();
        $this->actingAsApiUser('admin')->getJson('/api/system/diagnostics')->assertOk()->assertJsonPath('release.version', Release::version());
    }

    public function test_a_created_checksum_or_selected_backup_cannot_satisfy_full_backup_health(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/system-integrity')->assertOk()->assertJsonFragment(['key' => 'backup_recency', 'status' => 'attention']);
        $this->postJson('/api/backup/export', ['modules' => ['products'], 'passphrase' => 'separate-secure-key'])->assertOk();
        $this->getJson('/api/system-integrity')->assertOk()->assertJsonFragment(['key' => 'backup_recency', 'status' => 'attention']);
    }
}
