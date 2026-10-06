<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\BackupRun;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Rfq;
use App\Models\RfqSupplier;
use App\Models\SupplierQuote;
use App\Models\SupplierQuoteItem;
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

    public function test_customer_sales_history_restores_archived_without_promoting_or_replaying_predictions(): void
    {
        $this->actingAsApiUser();$source=$this->apiCompany;
        $customer=DB::table('customers')->insertGetId(['company_id'=>$source->id,'name'=>'V8 source buyer','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $snapshot=DB::table('analytics_snapshots')->insertGetId(['company_id'=>$source->id,'entity_type'=>'customer_sales_v8','entity_id'=>$customer,'warehouse_id'=>0,'snapshot_date'=>today(),'observed_at'=>now(),'feature_version'=>'customer-sales-v8.1','facts'=>json_encode(['id'=>$customer]),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('analytics_predictions')->insert(['company_id'=>$source->id,'prediction_type'=>'reorder_window','entity_type'=>'customer','entity_id'=>$customer,'model_key'=>'customer-sales-v8','model_version'=>'median_mad','input_feature_version'=>'customer-sales-v8.1','analytics_snapshot_id'=>$snapshot,'value'=>json_encode(['key'=>'reorder:source','customer_id'=>$customer]),'generated_at'=>now(),'valid_until'=>now()->addDays(30),'evaluation'=>json_encode(['timing_qualified'=>true]),'evaluated_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('customer_sales_snapshots')->insert(['company_id'=>$source->id,'version'=>(string)Str::uuid(),'fingerprint'=>str_repeat('a',64),'as_of'=>today(),'evidence_cutoff'=>now(),'evidence'=>json_encode(['profiles'=>[['id'=>$customer]],'opportunities'=>[]]),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('customer_intelligence_policies')->insert(['company_id'=>$source->id,'version'=>(string)Str::uuid(),'settings'=>json_encode(['champion'=>'median_mad']),'created_at'=>now(),'updated_at'=>now()]);
        $contents=$this->plainBackup($source,['customer_debts','analytics']);$target=$this->switchToNewCompany();$file=UploadedFile::fake()->createWithContent('v8-backup.json',$contents);$this->post('/api/backup/import',['file'=>$file,'mode'=>'merge'])->assertOk();
        $summary=DB::table('customer_sales_snapshots')->where('company_id',$target->id)->first();$this->assertTrue(json_decode($summary->evidence,true)['archived']);$prediction=DB::table('analytics_predictions')->where('company_id',$target->id)->first();$this->assertTrue(json_decode($prediction->value,true)['archived']);$this->assertNull($prediction->evaluated_at);$this->assertSame($prediction->generated_at,$prediction->valid_until);$this->assertSame('interval_iqr_baseline',json_decode(DB::table('customer_intelligence_policies')->where('company_id',$target->id)->value('settings'),true)['champion']);
    }

    public function test_pre_analytics_full_backup_remains_restorable(): void
    {
        $this->actingAsApiUser();
        $source = $this->apiCompany;
        $this->makeProduct($source, 'LEGACY-FULL', 'Legacy full product');
        $modules = array_values(array_diff(array_keys(PortableBackupService::MODULES), ['analytics']));
        $contents = $this->plainBackup($source,$modules);
        $target = $this->switchToNewCompany();
        DB::table('analytics_snapshots')->insert([
            'company_id'=>$target->id,'snapshot_date'=>today(),'entity_type'=>'backlog','entity_id'=>0,'warehouse_id'=>0,
            'feature_version'=>'observed-v1','observed_at'=>now(),'facts'=>'{}','created_at'=>now(),'updated_at'=>now(),
        ]);
        $file = UploadedFile::fake()->createWithContent('legacy-full.json',$contents);
        $this->post('/api/backup/import',['file'=>$file,'mode'=>'replace','confirm_replace'=>'1'])
            ->assertOk()->assertJsonCount(1,'warnings');
        $this->assertDatabaseHas('products',['company_id'=>$target->id,'sku'=>'LEGACY-FULL']);
        $this->assertSame(0,DB::table('analytics_snapshots')->where('company_id',$target->id)->count());
    }

    public function test_analytics_backup_restores_immutable_history_and_remaps_entities_across_companies(): void
    {
        $this->actingAsApiUser();
        $source = $this->apiCompany;
        $product = $this->makeProduct($source, 'ANALYTICS-BACKUP', 'Analytics product');
        $snapshot = DB::table('analytics_snapshots')->insertGetId([
            'company_id'=>$source->id, 'snapshot_date'=>today()->toDateString(), 'entity_type'=>'product',
            'entity_id'=>$product->id, 'warehouse_id'=>0, 'feature_version'=>'observed-v1',
            'observed_at'=>now(), 'facts'=>json_encode(['id'=>$product->id,'unit'=>'pcs','on_hand'=>12]), 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        $version = (string) Str::uuid();
        $dataset = DB::table('analytics_datasets')->insertGetId([
            'company_id'=>$source->id, 'version'=>$version, 'name'=>'backup-test', 'date_from'=>today(), 'date_to'=>today(),
            'feature_definitions'=>'{}', 'row_count'=>1, 'labelled_count'=>0, 'quality_status'=>'waiting_for_outcomes', 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        $frozenValues = json_encode(['product_id'=>$product->id, 'unit'=>'pcs', 'on_hand'=>12]);
        DB::table('analytics_dataset_rows')->insert(['company_id'=>$source->id,'analytics_dataset_id'=>$dataset,'analytics_snapshot_id'=>$snapshot,'values'=>$frozenValues]);
        $artifact=['version'=>'demand-v1','algorithm'=>'seasonal_mean','parameters'=>[],'training_cutoff'=>today()->subDay()->toDateString()];
        $modelVersion=(string)Str::uuid();
        DB::table('inventory_forecast_models')->insert(['company_id'=>$source->id,'product_id'=>$product->id,'analytics_dataset_id'=>$dataset,'version'=>$modelVersion,'feature_version'=>'demand-daily-v1','horizon'=>30,'algorithm'=>'seasonal_mean','status'=>'active','training_cutoff'=>today()->subDay(),'artifact'=>json_encode($artifact),'artifact_hash'=>\App\Services\InventoryIntelligenceService::artifactHash($artifact),'comparison'=>'{}','quality'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        $modelId=DB::table('inventory_forecast_models')->where('version',$modelVersion)->value('id');$decisionKey=(string)Str::uuid();
        DB::table('inventory_model_decisions')->insert(['company_id'=>$source->id,'product_id'=>$product->id,'to_model_id'=>$modelId,'horizon'=>30,'decision_key'=>$decisionKey,'action'=>'promote','reason'=>'Reviewed real history','evidence'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        DB::table('inventory_intelligence_alerts')->insert(['company_id'=>$source->id,'product_id'=>$product->id,'horizon'=>30,'code'=>'accuracy_degraded','status'=>'open','episode'=>1,'evidence'=>'{}','opened_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        $prediction=DB::table('analytics_predictions')->insertGetId(['company_id'=>$source->id,'prediction_type'=>'inventory_demand','entity_type'=>'product','entity_id'=>$product->id,'model_key'=>'inventory-demand-v1','model_version'=>$modelVersion,'input_feature_version'=>'demand-daily-v1','analytics_snapshot_id'=>$snapshot,'value'=>json_encode(['horizon'=>30,'unit'=>'pcs','daily'=>[]]),'generated_at'=>now(),'valid_until'=>now()->addDay(),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('inventory_recommendations')->insert(['company_id'=>$source->id,'product_id'=>$product->id,'analytics_prediction_id'=>$prediction,'status'=>'open','risk'=>'watch','explanation'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        $contents = $this->plainBackup($source, ['analytics']);
        $archive = json_decode($contents,true);
        $this->assertCount(1,$archive['payload']['data']['products']);
        $target = $this->switchToNewCompany();
        $file = UploadedFile::fake()->createWithContent('analytics.json',$contents);
        $this->post('/api/backup/import',['file'=>$file,'mode'=>'merge'])->assertOk();
        $restored = DB::table('analytics_snapshots')->where('company_id',$target->id)->first();
        $newProduct = DB::table('products')->where('company_id',$target->id)->where('sku','ANALYTICS-BACKUP')->first();
        $this->assertSame($newProduct->id,$restored->entity_id);
        $this->assertNotEquals($product->id,$restored->entity_id);
        $this->assertDatabaseHas('analytics_datasets',['company_id'=>$target->id,'version'=>$version]);
        $this->assertDatabaseHas('inventory_forecast_models',['company_id'=>$target->id,'product_id'=>$newProduct->id,'version'=>$modelVersion,'status'=>'archived']);
        $newModelId=DB::table('inventory_forecast_models')->where('company_id',$target->id)->where('version',$modelVersion)->value('id');
        $this->assertDatabaseHas('inventory_model_decisions',['company_id'=>$target->id,'product_id'=>$newProduct->id,'to_model_id'=>$newModelId,'decision_key'=>$decisionKey]);
        $this->assertDatabaseHas('inventory_intelligence_alerts',['company_id'=>$target->id,'product_id'=>$newProduct->id,'status'=>'resolved']);
        $this->assertDatabaseHas('inventory_recommendations',['company_id'=>$target->id,'product_id'=>$newProduct->id,'status'=>'superseded']);
        $restoredPrediction=DB::table('analytics_predictions')->where('company_id',$target->id)->first();
        $this->assertSame($restoredPrediction->generated_at,$restoredPrediction->valid_until);
        $this->assertSame($frozenValues,DB::table('analytics_dataset_rows')->where('company_id',$target->id)->value('values'));
        $this->post('/api/backup/import',['file'=>$file,'mode'=>'merge'])->assertOk();
        $this->assertSame(1,DB::table('analytics_snapshots')->where('company_id',$target->id)->count());
        $this->assertSame(1,DB::table('analytics_dataset_rows')->where('company_id',$target->id)->count());
        $this->assertSame(1,DB::table('inventory_model_decisions')->where('company_id',$target->id)->count());
        $archive['payload']['data']['analytics_snapshots'][0]['facts'] = '{"on_hand":999}';
        $conflicting = UploadedFile::fake()->createWithContent('conflicting.json',$this->resignArchive($archive));
        $this->post('/api/backup/import',['file'=>$conflicting,'mode'=>'merge'])->assertStatus(422);
        $this->assertSame(12,json_decode(DB::table('analytics_snapshots')->where('company_id',$target->id)->value('facts'),true)['on_hand']);
    }

    public function test_backup_execution_history_records_verified_success_and_safe_failure(): void
    {
        $this->actingAsApiUser('admin');
        $passphrase = 'history-secret-passphrase';
        $backup = $this->postJson('/api/backup/export', [
            'modules' => ['products'],
            'passphrase' => $passphrase,
        ])->assertOk()->getContent();

        $completed = BackupRun::query()->where('operation', 'export')->latest('id')->firstOrFail();
        $this->assertSame('completed', $completed->status);
        $this->assertSame('checksum_created', $completed->verification_result);
        $this->assertGreaterThan(0, $completed->size_bytes);
        $this->assertSame(['products'], $completed->modules);

        $file = UploadedFile::fake()->createWithContent('wrong-password.aimsbackup', $backup);
        $this->post('/api/backup/import', [
            'file' => $file,
            'passphrase' => 'another-valid-passphrase',
        ])->assertUnprocessable();

        $failed = BackupRun::query()->where('operation', 'restore')->latest('id')->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertNotNull($failed->completed_at);
        $this->assertStringNotContainsString($passphrase, (string) $failed->error_summary);
        $this->assertStringNotContainsString('another-valid-passphrase', (string) $failed->error_summary);

        $this->getJson('/api/system-integrity')->assertOk()
            ->assertJsonPath('backup_history.0.status', 'failed')
            ->assertJsonPath('backup_history.1.status', 'completed');
    }

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
        $this->assertSame(0.0, (float) $restored->quantity);
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

    public function test_procurement_backup_restores_request_rfq_quote_relationships_without_provider_secrets(): void
    {
        $this->actingAsApiUser('admin');
        $user = User::query()->where('company_id', $this->apiCompany->id)->firstOrFail();
        $product = $this->makeProduct($this->apiCompany, 'PROC-BACKUP', 'Procurement backup product');
        $request = PurchaseRequest::create($this->tenantAttributes([
            'request_number' => 'PR-BACKUP-1', 'status' => 'approved', 'requested_by' => $user->id,
            'requested_at' => now(), 'required_by' => now()->addWeek(), 'estimated_total' => 100, 'currency' => 'EUR',
        ]));
        $requestItem = PurchaseRequestItem::create(['purchase_request_id' => $request->id, 'product_id' => $product->id, 'description' => $product->name, 'unit' => 'pcs', 'quantity' => 10, 'estimated_unit_price' => 10]);
        $rfq = Rfq::create($this->tenantAttributes(['purchase_request_id' => $request->id, 'rfq_number' => 'RFQ-BACKUP-1', 'status' => 'issued', 'issued_at' => now(), 'created_by' => $user->id]));
        RfqSupplier::create(['rfq_id' => $rfq->id, 'supplier_id' => $product->supplier_id, 'sent_at' => now(), 'status' => 'quoted']);
        $quote = SupplierQuote::create($this->tenantAttributes(['rfq_id' => $rfq->id, 'supplier_id' => $product->supplier_id, 'revision' => 1, 'status' => 'received', 'currency' => 'EUR', 'exchange_rate' => 1, 'created_by' => $user->id]));
        SupplierQuoteItem::create(['supplier_quote_id' => $quote->id, 'purchase_request_item_id' => $requestItem->id, 'offered_quantity' => 10, 'unit_price' => 9.50]);

        $backup = $this->postJson('/api/backup/export', ['modules' => ['procurement'], 'passphrase' => 'procurement-backup-passphrase'])->assertOk()->getContent();
        $this->assertStringNotContainsString('integration_providers', $backup);
        $this->assertStringNotContainsString('webhook_endpoints', $backup);

        $target = $this->switchToNewCompany();
        $file = UploadedFile::fake()->createWithContent('procurement.aimsbackup', $backup);
        $this->post('/api/backup/import', ['file' => $file, 'passphrase' => 'procurement-backup-passphrase', 'mode' => 'merge'])->assertOk();

        $restoredRequest = DB::table('purchase_requests')->where('company_id', $target->id)->where('request_number', 'PR-BACKUP-1')->first();
        $restoredRfq = DB::table('rfqs')->where('company_id', $target->id)->where('rfq_number', 'RFQ-BACKUP-1')->first();
        $this->assertNotNull($restoredRequest);
        $this->assertSame((int) $restoredRequest->id, (int) $restoredRfq->purchase_request_id);
        $this->assertDatabaseHas('purchase_request_items', ['purchase_request_id' => $restoredRequest->id, 'description' => 'Procurement backup product']);
        $this->assertDatabaseHas('supplier_quotes', ['company_id' => $target->id, 'rfq_id' => $restoredRfq->id, 'revision' => 1]);
    }

    public function test_partial_replace_is_blocked_but_full_replace_restores_business_data(): void
    {
        $this->actingAsApiUser('admin');
        $sourceCompany = $this->apiCompany;
        $this->makeProduct($sourceCompany, 'FULL-SOURCE', 'Full source product');
        $this->postJson('/api/accounting/initialize')->assertOk();
        $partialBackup = $this->postJson('/api/backup/export', [
            'modules' => ['products'],
            'passphrase' => 'partial-replace-passphrase',
        ])->assertOk()->getContent();
        $fullBackup = $this->postJson('/api/backup/export', [
            'passphrase' => 'complete-replace-passphrase',
        ])->assertOk()->getContent();

        $targetCompany = $this->switchToNewCompany();
        $this->makeProduct($targetCompany, 'TARGET-OLD', 'Old target product');
        $this->postJson('/api/accounting/initialize')->assertOk();

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
        $restoredCash = \App\Models\AccountingAccount::where('code', '1000')->firstOrFail();
        $this->assertSame('100', $restoredCash->parent->code);
        $this->assertSame($targetCompany->id, $restoredCash->parent->company_id);
        $this->assertDatabaseHas('accounting_accounts', ['company_id' => $sourceCompany->id, 'code' => '1000']);
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

    public function test_product_only_restore_never_overwrites_an_existing_inventory_quantity(): void
    {
        $this->actingAsApiUser('admin');
        $sourceCompany = $this->apiCompany;
        $this->makeProduct($sourceCompany, 'PRESERVE-QTY', 'Restored product master');
        $backup = $this->plainBackup($sourceCompany, ['products']);

        $targetCompany = $this->switchToNewCompany();
        $target = $this->makeProduct($targetCompany, 'PRESERVE-QTY', 'Existing product master');
        $target->update(['quantity' => 7]);
        DB::table('warehouse_stock')->where('product_id', $target->id)->update([
            'quantity' => 7,
            'available_quantity' => 7,
            'updated_at' => now(),
        ]);

        $file = UploadedFile::fake()->createWithContent('products-only.aimsbackup', $backup);
        $this->post('/api/backup/import', [
            'file' => $file,
            'mode' => 'merge',
        ])->assertOk();

        $restored = $target->fresh();
        $this->assertSame('Restored product master', $restored->name);
        $this->assertSame(7.0, (float) $restored->quantity);
        $this->assertSame(7.0, (float) DB::table('warehouse_stock')->where('product_id', $target->id)->value('quantity'));
    }

    public function test_product_restore_rebuilds_primary_and_alternative_barcode_identities(): void
    {
        $this->actingAsApiUser('admin');
        $sourceCompany = $this->apiCompany;
        $product = $this->makeProduct($sourceCompany, 'NORMALIZE-CODES', 'Normalize codes');
        DB::table('products')->where('id', $product->id)->update([
            'barcode' => '  Primary   Code  ',
            'barcode_normalized' => 'stale-primary-identity',
        ]);
        DB::table('product_barcodes')->insert([
            'company_id' => $sourceCompany->id,
            'product_id' => $product->id,
            'barcode' => '  Alternate   Code  ',
            'barcode_normalized' => 'stale-alternative-identity',
            'label' => 'Case',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $backup = $this->plainBackup($sourceCompany, ['products']);

        $targetCompany = $this->switchToNewCompany();
        $file = UploadedFile::fake()->createWithContent('normalized-barcodes.aimsbackup', $backup);
        $this->post('/api/backup/import', ['file' => $file])->assertOk();

        $restored = DB::table('products')
            ->where('company_id', $targetCompany->id)
            ->where('sku', 'NORMALIZE-CODES')
            ->first();
        $this->assertSame('Primary   Code', $restored->barcode);
        $this->assertSame('primary code', $restored->barcode_normalized);
        $this->assertDatabaseHas('product_barcodes', [
            'company_id' => $targetCompany->id,
            'product_id' => $restored->id,
            'barcode' => 'Alternate   Code',
            'barcode_normalized' => 'alternate code',
        ]);
    }

    public function test_product_restore_rejects_a_cross_table_barcode_collision_atomically(): void
    {
        $this->actingAsApiUser('admin');
        $sourceCompany = $this->apiCompany;
        $source = $this->makeProduct($sourceCompany, 'COLLIDING-ALT', 'Colliding alternative');
        DB::table('product_barcodes')->insert([
            'company_id' => $sourceCompany->id,
            'product_id' => $source->id,
            'barcode' => '  Shared   Code  ',
            'barcode_normalized' => 'archive-stale-identity',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $backup = $this->plainBackup($sourceCompany, ['products']);

        $targetCompany = $this->switchToNewCompany();
        $target = $this->makeProduct($targetCompany, 'TARGET-BARCODE', 'Target barcode owner');
        DB::table('warehouses')->where('id', $target->default_warehouse_id)->update([
            'name' => 'Target-only warehouse',
        ]);
        DB::table('products')->where('id', $target->id)->update([
            'barcode' => 'shared code',
            'barcode_normalized' => 'shared code',
        ]);

        $file = UploadedFile::fake()->createWithContent('barcode-conflict.aimsbackup', $backup);
        $this->post('/api/backup/import', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseMissing('products', [
            'company_id' => $targetCompany->id,
            'sku' => 'COLLIDING-ALT',
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $target->id,
            'barcode_normalized' => 'shared code',
        ]);
    }

    public function test_restore_with_inconsistent_warehouse_stock_rolls_back_every_imported_row(): void
    {
        $this->actingAsApiUser('admin');
        $sourceCompany = $this->apiCompany;
        $product = $this->makeProduct($sourceCompany, 'BAD-INVENTORY', 'Bad inventory');
        $archive = json_decode(
            $this->plainBackup($sourceCompany, ['inventory']),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $archive['payload']['data']['warehouse_stock'][0]['quantity'] = '10.000';
        $archive['payload']['data']['warehouse_stock'][0]['available_quantity'] = '10.000';
        $backup = $this->resignArchive($archive);

        $targetCompany = $this->switchToNewCompany();
        $file = UploadedFile::fake()->createWithContent('inconsistent-inventory.aimsbackup', $backup);
        $this->post('/api/backup/import', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('inventory');

        $this->assertDatabaseMissing('products', [
            'company_id' => $targetCompany->id,
            'sku' => 'BAD-INVENTORY',
        ]);
        $this->assertSame(0, DB::table('warehouse_stock')->where('company_id', $targetCompany->id)->count());
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

        $product = Product::withoutEvents(fn () => Product::withoutGlobalScopes()->create([
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
        DB::table('warehouse_stock')->insert([
            'company_id' => $company->id,
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'location_id' => null,
            'location_key' => 0,
            'quantity' => 12,
            'available_quantity' => 12,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'quarantine_quantity' => 0,
            'blocked_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $product;
    }

    private function resignArchive(array $archive): string
    {
        unset($archive['checksum']);
        $archive['checksum'] = 'sha256:'.hash('sha256', json_encode(
            $archive,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));

        return json_encode($archive, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function plainBackup(Company $company, array $modules): string
    {
        $user = User::query()->where('company_id', $company->id)->firstOrFail();
        Auth::login($user);
        try {
            return app(PortableBackupService::class)->export($modules)->getContent();
        } finally {
            Auth::logout();
        }
    }
}
