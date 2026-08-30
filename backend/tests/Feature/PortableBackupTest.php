<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\PortableBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortableBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_selective_plain_service_export_is_tenant_safe_and_contains_no_auth_data(): void
    {
        $this->actingAsApiUser('admin');
        $sourceCompany = $this->apiCompany;
        $sourceUser = User::query()->where('company_id', $sourceCompany->id)->firstOrFail();
        $this->makeProduct($sourceCompany, 'SOURCE-SKU', 'Source product');

        $otherCompany = Company::factory()->create();
        $this->makeProduct($otherCompany, 'OTHER-SKU', 'Other tenant product');
        Auth::login($sourceUser);

        $response = app(PortableBackupService::class)->export(['products']);
        $archive = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(PortableBackupService::FORMAT, $archive['format']);
        $this->assertSame(['products'], $archive['manifest']['modules']);
        $this->assertSame('Source product', $archive['payload']['data']['products'][0]['name']);
        $this->assertSame('Source category', $archive['payload']['data']['categories'][0]['name']);
        $this->assertArrayNotHasKey('users', $archive['payload']['data']);
        $this->assertStringNotContainsString('Other tenant product', $response->getContent());
        $this->assertStringNotContainsString((string) $sourceUser->password, $response->getContent());
        $this->assertStringNotContainsString((string) $sourceUser->api_token, $response->getContent());
    }

    public function test_encrypted_selective_backup_round_trips_with_dependency_and_id_remapping(): void
    {
        $this->actingAsApiUser('admin');
        $sourceCompany = $this->apiCompany;
        $product = $this->makeProduct($sourceCompany, 'PORTABLE-1', 'Portable product');
        $product->forceFill([
            'image_data' => base64_encode('image bytes'),
            'image_mime' => 'image/png',
        ])->save();

        $passphrase = 'portable-backup-passphrase';
        $backup = $this->postJson('/api/backup/export', [
            'modules' => ['products'],
            'passphrase' => $passphrase,
        ])->assertOk();
        $envelope = json_decode($backup->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(PortableBackupService::ENCRYPTED_FORMAT, $envelope['format']);
        $this->assertSame('AES-256-GCM', $envelope['encryption']['cipher']);
        $this->assertStringNotContainsString('Portable product', $backup->getContent());

        $targetCompany = $this->switchToNewCompany();
        $file = UploadedFile::fake()->createWithContent('portable.aimsbackup', $backup->getContent());
        $this->post('/api/backup/import', [
            'file' => $file,
            'passphrase' => $passphrase,
            'mode' => 'merge',
        ])->assertOk()->assertJsonPath('modules.0', 'products');

        $restored = DB::table('products')->where('company_id', $targetCompany->id)->where('sku', 'PORTABLE-1')->first();
        $this->assertNotNull($restored);
        $this->assertSame(base64_encode('image bytes'), $restored->image_data);
        $this->assertDatabaseHas('categories', [
            'id' => $restored->category_id,
            'company_id' => $targetCompany->id,
            'name' => 'Source category',
        ]);
        $this->assertDatabaseHas('suppliers', [
            'id' => $restored->supplier_id,
            'company_id' => $targetCompany->id,
            'name' => 'Source supplier',
        ]);
    }

    public function test_wrong_passphrase_and_tampering_are_rejected_before_any_data_changes(): void
    {
        $this->actingAsApiUser('admin');
        $sourceCompany = $this->apiCompany;
        $this->makeProduct($sourceCompany, 'SECURE-1', 'Secure product');
        $backup = $this->postJson('/api/backup/export', [
            'modules' => ['products'],
            'passphrase' => 'correct-secure-passphrase',
        ])->assertOk()->getContent();

        $targetCompany = $this->switchToNewCompany();
        $wrong = UploadedFile::fake()->createWithContent('wrong.aimsbackup', $backup);
        $this->post('/api/backup/import', [
            'file' => $wrong,
            'passphrase' => 'incorrect-passphrase',
        ])->assertUnprocessable();
        $this->assertSame(0, DB::table('products')->where('company_id', $targetCompany->id)->count());

        $envelope = json_decode($backup, true, 512, JSON_THROW_ON_ERROR);
        $envelope['payload'][5] = $envelope['payload'][5] === 'A' ? 'B' : 'A';
        $tampered = UploadedFile::fake()->createWithContent('tampered.aimsbackup', json_encode($envelope));
        $this->post('/api/backup/import', [
            'file' => $tampered,
            'passphrase' => 'correct-secure-passphrase',
        ])->assertUnprocessable();
        $this->assertSame(0, DB::table('products')->where('company_id', $targetCompany->id)->count());
    }

    public function test_partial_replace_is_blocked_but_full_replace_restores_business_data(): void
    {
        $this->actingAsApiUser('admin');
        $sourceCompany = $this->apiCompany;
        $this->makeProduct($sourceCompany, 'FULL-SOURCE', 'Full source product');
        $partialBackup = $this->postJson('/api/backup/export', [
            'modules' => ['products'],
            'passphrase' => 'partial-replace-passphrase',
        ])->assertOk()->getContent();
        $fullBackup = $this->postJson('/api/backup/export', [
            'passphrase' => 'complete-replace-passphrase',
        ])->assertOk()->getContent();

        $targetCompany = $this->switchToNewCompany();
        $this->makeProduct($targetCompany, 'TARGET-OLD', 'Old target product');

        $partialFile = UploadedFile::fake()->createWithContent('partial.aimsbackup', $partialBackup);
        $this->post('/api/backup/import', [
            'file' => $partialFile,
            'passphrase' => 'partial-replace-passphrase',
            'mode' => 'replace',
            'confirm_replace' => '1',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('products', ['company_id' => $targetCompany->id, 'sku' => 'TARGET-OLD']);

        $fullFile = UploadedFile::fake()->createWithContent('full.aimsbackup', $fullBackup);
        $this->post('/api/backup/import', [
            'file' => $fullFile,
            'passphrase' => 'complete-replace-passphrase',
            'mode' => 'replace',
            'confirm_replace' => '1',
        ])->assertOk()->assertJsonPath('mode', 'replace');

        $this->assertDatabaseMissing('products', ['company_id' => $targetCompany->id, 'sku' => 'TARGET-OLD']);
        $this->assertDatabaseHas('products', ['company_id' => $targetCompany->id, 'sku' => 'FULL-SOURCE']);
        $this->assertDatabaseHas('companies', ['id' => $targetCompany->id, 'name' => $sourceCompany->name]);
    }

    public function test_two_unnumbered_draft_invoices_restore_once_and_repeat_merge_is_rejected(): void
    {
        $this->actingAsApiUser('admin');
        $sourceCompany = $this->apiCompany;
        foreach (['Draft buyer one', 'Draft buyer two'] as $buyer) {
            DB::table('invoices')->insert([
                'company_id' => $sourceCompany->id,
                'invoice_number' => null,
                'customer_name' => $buyer,
                'status' => 'draft',
                'document_type' => 'invoice',
                'compliance_status' => 'draft',
                'payment_status' => 'unpaid',
                'currency' => 'EUR',
                'invoice_date' => '2026-08-27',
                'total_amount' => 0,
                'total_paid' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $backup = $this->postJson('/api/backup/export', [
            'modules' => ['finance'],
            'passphrase' => 'draft-invoice-passphrase',
        ])->assertOk()->getContent();

        $targetCompany = $this->switchToNewCompany();
        $first = UploadedFile::fake()->createWithContent('drafts.aimsbackup', $backup);
        $this->post('/api/backup/import', [
            'file' => $first,
            'passphrase' => 'draft-invoice-passphrase',
            'mode' => 'merge',
        ])->assertOk();
        $this->assertSame(2, DB::table('invoices')->where('company_id', $targetCompany->id)->whereNull('invoice_number')->count());

        $second = UploadedFile::fake()->createWithContent('drafts-again.aimsbackup', $backup);
        $this->post('/api/backup/import', [
            'file' => $second,
            'passphrase' => 'draft-invoice-passphrase',
            'mode' => 'merge',
        ])->assertUnprocessable();
        $this->assertSame(2, DB::table('invoices')->where('company_id', $targetCompany->id)->whereNull('invoice_number')->count());
    }

    private function switchToNewCompany(): Company
    {
        $company = Company::factory()->create();
        $plainToken = 'portable-'.Str::uuid();
        User::factory()->create([
            'company_id' => $company->id,
            'role' => 'admin',
            'api_token' => hash('sha256', $plainToken),
            'email_verified_at' => now(),
        ]);
        $this->apiCompany = $company;
        $this->withHeader('Authorization', 'Bearer '.$plainToken);

        return $company;
    }

    private function makeProduct(Company $company, string $sku, string $name): Product
    {
        $categoryId = DB::table('categories')->insertGetId([
            'company_id' => $company->id,
            'name' => 'Source category',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $supplierId = DB::table('suppliers')->insertGetId([
            'company_id' => $company->id,
            'name' => 'Source supplier',
            'email' => strtolower($sku).'@supplier.test',
            'phone' => '000',
            'address' => 'Supplier address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = DB::table('warehouses')->insertGetId([
            'company_id' => $company->id,
            'name' => 'Source warehouse',
            'code' => 'WH-'.$sku,
            'address' => 'Warehouse address',
            'is_active' => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Product::withoutEvents(fn () => Product::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'category_id' => $categoryId,
            'supplier_id' => $supplierId,
            'default_warehouse_id' => $warehouseId,
            'name' => $name,
            'sku' => $sku,
            'quantity' => 12,
            'unit' => 'pcs',
            'min_quantity' => 2,
            'price' => 5,
            'purchase_price' => 3,
            'selling_price' => 5,
        ]));
    }
}
