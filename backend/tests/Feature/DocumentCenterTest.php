<?php

namespace Tests\Feature;

use App\Contracts\DocumentStorageProvider;
use App\Models\ApprovalRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\Expense;
use App\Models\User;
use App\Services\DocumentMaintenanceService;
use App\Services\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentCenterTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        $token = 'doc-user-'.$user->id;
        $user->update(['api_token' => hash('sha256', $token), 'email_verified_at' => now()]);

        return $token;
    }

    private function setupUser(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/documents/config')->assertOk();
    }

    private function upload(string $text = 'Business evidence', array $extra = []): array
    {
        return $this->post('/api/documents', ['title' => 'Evidence', 'document_type_id' => DocumentType::first()->id, 'file' => UploadedFile::fake()->createWithContent('evidence.txt', $text), ...$extra], ['Accept' => 'application/json'])->assertCreated()->json();
    }

    public function test_upload_private_download_duplicate_and_immutable_versions(): void
    {
        $this->setupUser();
        $d = $this->upload();
        $this->assertStringNotContainsString('storage_key', json_encode($d));
        $this->get('/api/documents/'.$d['id'].'/versions/1/file')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/api/documents', ['title' => 'Duplicate', 'document_type_id' => DocumentType::first()->id, 'file' => UploadedFile::fake()->createWithContent('evidence.txt', 'Business evidence')], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('duplicate', true);
        $this->post('/api/documents/'.$d['id'].'/versions', ['change_note' => 'Updated wording', 'file' => UploadedFile::fake()->createWithContent('revision.txt', 'New evidence')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('current_version', 2);
        $v = DocumentVersion::where('document_id', $d['id'])->where('version', 1)->first();
        $this->assertSame(hash('sha256', 'Business evidence'), $v->checksum);
        $this->get('/api/documents/'.$d['id'].'/versions/1/file')->assertOk();
        $this->expectException(\LogicException::class);
        $v->update(['checksum' => str_repeat('a', 64)]);
    }

    public function test_unsafe_types_and_paths_rejected(): void
    {
        $this->setupUser();
        foreach (['payload.php.txt', 'script.exe', 'vector.svg'] as $name) {
            $this->post('/api/documents', ['title' => 'Unsafe', 'document_type_id' => DocumentType::first()->id, 'file' => UploadedFile::fake()->createWithContent($name, '<?php dangerous ?>')], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->post('/api/documents', ['title' => 'Mismatch', 'document_type_id' => DocumentType::first()->id, 'file' => UploadedFile::fake()->createWithContent('fake.pdf', 'not a pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->expectException(\InvalidArgumentException::class);
        app(DocumentStorageProvider::class)->read('../.env');
    }

    public function test_restore_cleanup_preserves_referenced_bytes_and_verifies_every_duplicate_version(): void
    {
        $this->setupUser();$d=$this->upload('Staged evidence');$version=DocumentVersion::where('document_id',$d['id'])->firstOrFail();
        $service=app(\App\Services\DocumentBackupService::class);$storage=app(DocumentStorageProvider::class);
        $versions=[$version->toArray()+['storage_key'=>$version->storage_key,'provider'=>'local']];
        $files=$service->export($versions,'test-document-secret');
        $keys=$service->restoreFiles($versions,$files,$version->company_id);$staged=array_values($keys)[0];
        $this->assertTrue($storage->exists($staged));$service->discardStaged($keys);
        $this->assertFalse($storage->exists($staged));$service->discardStaged([$version->storage_key]);$this->assertTrue($storage->exists($version->storage_key));
        $backup=$this->postJson('/api/backup/export',['modules'=>['documents'],'passphrase'=>'test-document-secret'])->assertOk()->getContent();
        $this->post('/api/backup/import',['file'=>UploadedFile::fake()->createWithContent('document.aimsbackup',$backup),'passphrase'=>'test-document-secret'],['Accept'=>'application/json'])->assertOk();
        $this->assertSame($version->storage_key,$version->fresh()->storage_key);
        $copies=[$versions[0],[...$versions[0],'version'=>2,'checksum'=>str_repeat('a',64)]];
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->restoreFiles($copies,$files,$version->company_id);
    }

    public function test_document_structural_integrity_is_visible_without_reading_file_bytes(): void
    {
        $this->setupUser();$d=$this->upload();
        DB::table('documents')->where('id',$d['id'])->update(['current_version'=>99]);
        $checks=collect($this->getJson('/api/system-integrity')->assertOk()->json('checks'));
        $this->assertEquals(1,$checks->firstWhere('key','document_current_version')['count']??$checks->firstWhere('id','document_current_version')['count']);
    }

    public function test_tenant_and_confidentiality_are_enforced(): void
    {
        $this->setupUser();
        $d = $this->upload('Secret', ['confidentiality' => 'restricted']);
        $staff = User::create(['name' => 'Worker', 'email' => 'doc-worker@example.test', 'password' => bcrypt('password'), 'role' => 'staff', 'company_id' => Auth::user()->company_id]);
        Auth::setUser($staff);
        $token = $this->token($staff);
        $this->withHeader('Authorization', 'Bearer '.$token);
        $this->getJson('/api/documents/'.$d['id'])->assertNotFound();
        $this->get('/api/documents/'.$d['id'].'/versions/1/file')->assertNotFound();
        $this->getJson('/api/documents')->assertOk()->assertJsonPath('total', 0);
        $foreign = Company::factory()->create(['name' => 'Other']);
        $u = User::create(['name' => 'Other', 'email' => 'doc-other@example.test', 'password' => bcrypt('password'), 'role' => 'admin', 'company_id' => $foreign->id]);
        $this->withHeader('Authorization', 'Bearer '.$this->token($u));
        Auth::setUser($u);
        $this->getJson('/api/documents/'.$d['id'])->assertNotFound();
    }

    public function test_links_requirements_archive_and_hold(): void
    {
        $this->setupUser();
        $d = $this->upload();
        $c = Customer::create($this->tenantAttributes(['name' => 'Client']));
        $id = $d['id'];
        $this->postJson('/api/documents/'.$id.'/link', ['entity_type' => 'customer', 'entity_id' => $c->id])->assertOk();
        $this->postJson('/api/documents/'.$id.'/link', ['entity_type' => 'customer', 'entity_id' => $c->id])->assertOk();
        $this->assertSame(1, DB::table('document_links')->count());
        $this->putJson('/api/documents/config', ['entity_type' => 'customer', 'document_type_id' => $d['document_type_id']])->assertOk();
        $this->getJson('/api/document-requirements/customer/'.$c->id)->assertOk()->assertJsonPath('status', 'complete');
        $held = $this->postJson('/api/documents/'.$id.'/hold', ['legal_hold' => true, 'hold_reason' => 'Keep evidence'])->assertOk()->json();
        $this->postJson('/api/documents/'.$id.'/unlink', ['link_id' => $held['links'][0]['link_id']])->assertUnprocessable();
        $this->postJson('/api/documents/'.$id.'/archive')->assertOk();
        $this->get('/api/documents/'.$id.'/versions/1/file')->assertOk();
        $this->getJson('/api/document-requirements/customer/'.$c->id)->assertOk()->assertJsonPath('status', 'incomplete');
        $this->postJson('/api/documents/'.$id.'/restore')->assertOk();
        $this->assertTrue(Document::find($id)->legal_hold);
    }

    public function test_review_reuses_engine_self_approval_and_new_version_invalidation(): void
    {
        $this->setupUser();
        $d = $this->upload();
        $id = $d['id'];
        $this->postJson('/api/documents/'.$id.'/review')->assertOk()->assertJsonPath('status', 'under_review');
        $this->postJson('/api/documents/'.$id.'/review')->assertOk();
        $this->assertSame(1, ApprovalRequest::count());
        $this->postJson('/api/documents/'.$id.'/decide', ['decision' => 'approved'])->assertUnprocessable();
        $u = User::create(['name' => 'Reviewer', 'email' => 'reviewer@example.test', 'password' => bcrypt('password'), 'role' => 'admin', 'company_id' => Auth::user()->company_id]);
        $this->withHeader('Authorization', 'Bearer '.$this->token($u));
        Auth::setUser($u);
        $this->postJson('/api/documents/'.$id.'/decide', ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');
        $this->post('/api/documents/'.$id.'/versions', ['change_note' => 'Different bytes', 'file' => UploadedFile::fake()->createWithContent('new.txt', 'different')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('status', 'active');
        $this->assertSame('cancelled', ApprovalRequest::first()->status);
    }

    public function test_expiry_notifications_deduplicate_and_integrity_detects_corruption(): void
    {
        $this->setupUser();
        $d = $this->upload('Expiry', ['expiry_date' => today()->addDays(4)->toDateString()]);
        $maintenance = app(DocumentMaintenanceService::class);
        $maintenance->expiryAlerts();
        $count = DB::table('notifications')->count();
        $maintenance->expiryAlerts();
        $this->assertGreaterThan(0, $count);
        $this->assertSame($count, DB::table('notifications')->count());
        $this->postJson('/api/documents/'.$d['id'].'/verify')->assertOk()->assertJsonPath('versions.0.integrity_status', 'valid');
        $v = DocumentVersion::first();
        DB::table('document_versions')->where('id', $v->id)->update(['checksum' => str_repeat('a', 64)]);
        $this->get('/api/documents/'.$d['id'].'/versions/1/file')->assertStatus(409);
        $this->assertNotEmpty($maintenance->verifyCompany(Auth::user()->company_id));
    }

    public function test_encrypted_backup_restores_bytes_versions_and_links_into_another_company(): void
    {
        $this->setupUser();
        $d = $this->upload();
        $c = Customer::create($this->tenantAttributes(['name' => 'Backup client']));
        $this->postJson('/api/documents/'.$d['id'].'/link', ['entity_type' => 'customer', 'entity_id' => $c->id])->assertOk();
        $content = $this->postJson('/api/backup/export', ['modules' => ['documents'], 'passphrase' => 'strong-document-secret'])->assertOk()->getContent();
        $company = Company::factory()->create(['name' => 'Restored company']);
        $u = User::create(['name' => 'Restorer', 'email' => 'restore-doc@example.test', 'password' => bcrypt('password'), 'role' => 'admin', 'company_id' => $company->id]);
        $this->withHeader('Authorization', 'Bearer '.$this->token($u));
        Auth::setUser($u);
        $this->post('/api/backup/import', ['file' => UploadedFile::fake()->createWithContent('backup.aimsbackup', $content), 'passphrase' => 'strong-document-secret'], ['Accept' => 'application/json'])->assertOk();
        $restored = Document::firstOrFail();
        $this->assertSame($d['uuid'], $restored->uuid);
        $this->get('/api/documents/'.$restored->id.'/versions/1/file')->assertOk();
        $link = $restored->links()->firstOrFail();
        $this->assertSame($company->id, Customer::findOrFail($link->entity_id)->company_id);
        $this->assertStringStartsWith($company->id.'/', DocumentVersion::first()->storage_key);
    }

    public function test_legacy_evidence_is_verified_imported_once_and_preserved(): void
    {
        $this->setupUser();
        $expense = Expense::create($this->tenantAttributes(['vendor_name' => 'Legacy vendor', 'vendor_key' => hash('sha256', 'legacy'), 'document_number_normalized' => 'old-1', 'received_date' => today(), 'document_type' => 'invoice', 'document_number' => 'OLD-1', 'invoice_date' => today(), 'currency' => 'EUR', 'exchange_rate' => 1, 'net_amount' => 10, 'vat_rate' => 0, 'vat_amount' => 0, 'gross_amount' => 10, 'vat_treatment' => 'exempt', 'net_amount_eur' => 10, 'vat_amount_eur' => 0, 'gross_amount_eur' => 10, 'status' => 'draft', 'proof_filename' => 'old.txt', 'proof_mime' => 'text/plain', 'proof_size' => 8, 'proof_sha256' => hash('sha256', 'Original'), 'proof_data' => 'Original']));
        $d = $this->postJson('/api/documents/import-legacy', ['source' => 'expense', 'source_id' => $expense->id])->assertOk()->json();
        $again = $this->postJson('/api/documents/import-legacy', ['source' => 'expense', 'source_id' => $expense->id])->assertOk()->json();
        $this->assertSame($d['id'], $again['id']);
        $this->assertSame('Original', $expense->fresh()->proof_data);
        $this->assertSame($expense->id, $d['links'][0]['id']);
        $this->get('/api/documents/'.$d['id'].'/versions/1/file')->assertOk();
        $expense->update(['proof_sha256' => str_repeat('f', 64)]);
        Document::whereKey($d['id'])->update(['legacy_key' => null]);
        $this->postJson('/api/documents/import-legacy', ['source' => 'expense', 'source_id' => $expense->id])->assertStatus(409);
    }

    public function test_document_only_restore_reuses_linked_po_without_overwriting_live_changes(): void
    {
        $this->setupUser();
        $supplier = \App\Models\Supplier::create($this->tenantAttributes(['name'=>'Document supplier']));
        $po = \App\Models\PurchaseOrder::create($this->tenantAttributes(['supplier_id'=>$supplier->id,'po_number'=>'DOC-PO-1','ordered_at'=>today(),'currency'=>'EUR','status'=>'draft','notes'=>'Original']));
        $d = $this->upload('PO evidence', ['entity_type'=>'purchase-order','entity_id'=>$po->id]);
        Document::findOrFail($d['id'])->update(['status'=>'approved']);
        $backup = $this->postJson('/api/backup/export', ['modules'=>['documents'],'passphrase'=>'document-po-secret'])->assertOk()->getContent();
        $this->post('/api/documents/'.$d['id'].'/versions', ['change_note'=>'New unapproved content','file'=>UploadedFile::fake()->createWithContent('new.txt','Newer PO evidence')], ['Accept'=>'application/json'])->assertCreated();
        $po->update(['notes'=>'Live note after backup']);
        $supplier->update(['name'=>'Live supplier name']);
        $this->post('/api/backup/import', ['file'=>UploadedFile::fake()->createWithContent('documents.aimsbackup',$backup),'passphrase'=>'document-po-secret'], ['Accept'=>'application/json'])->assertOk();
        $this->assertSame(1, \App\Models\PurchaseOrder::count());
        $this->assertSame('Live note after backup', $po->fresh()->notes);
        $this->assertSame('Live supplier name', $supplier->fresh()->name);
        $this->assertSame(2, Document::findOrFail($d['id'])->current_version);
        $this->assertSame('active', Document::findOrFail($d['id'])->status);
        $this->assertSame($po->id, (int) Document::findOrFail($d['id'])->links()->first()->entity_id);
        $this->get('/api/documents/'.$d['id'].'/versions/1/file')->assertOk();
    }

    public function test_compatibility_upload_stores_private_bytes_and_links_expense(): void
    {
        $this->setupUser();
        $expense = Expense::create($this->tenantAttributes(['vendor_name' => 'New vendor', 'vendor_key' => hash('sha256', 'new'), 'document_number_normalized' => 'new-1', 'received_date' => today(), 'document_type' => 'invoice', 'document_number' => 'NEW-1', 'invoice_date' => today(), 'currency' => 'EUR', 'exchange_rate' => 1, 'net_amount' => 10, 'vat_rate' => 0, 'vat_amount' => 0, 'gross_amount' => 10, 'vat_treatment' => 'exempt', 'net_amount_eur' => 10, 'vat_amount_eur' => 0, 'gross_amount_eur' => 10, 'status' => 'draft']));
        $image = UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        app(ExpenseService::class)->storeAttachment($expense, $image);
        $expense->refresh();
        $this->assertNotNull($expense->document_version_id);
        $this->assertNull($expense->proof_data);
        $this->assertTrue($expense->has_attachment);
        $v = DocumentVersion::findOrFail($expense->document_version_id);
        $this->assertSame($expense->id,$v->document->links()->first()->entity_id);
        $this->get('/api/documents/'.$v->document_id.'/versions/1/file')->assertOk();
        $backup=$this->postJson('/api/backup/export',['passphrase'=>'full-document-secret'])->assertOk()->getContent();
        $this->post('/api/backup/import',['file'=>UploadedFile::fake()->createWithContent('full.aimsbackup',$backup),'passphrase'=>'full-document-secret','mode'=>'replace','confirm_replace'=>true],['Accept'=>'application/json'])->assertOk();
        $restored=Expense::firstOrFail();
        $this->assertNotNull($restored->document_version_id);
        $version=DocumentVersion::findOrFail($restored->document_version_id);
        $this->get('/api/documents/'.$version->document_id.'/versions/1/file')->assertOk();
    }
}
