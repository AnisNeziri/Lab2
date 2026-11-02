<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\DocumentType;
use App\Services\InstallationBackupService;
use App\Contracts\DocumentStorageProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Artisan,Auth,DB,File};

class DC11EncryptedRecoveryTest extends TestCase
{
    public function test_authenticated_archive_restores_key_documents_and_business_state_only_into_an_empty_matching_installation(): void
    {
        $originalStorage=app()->storagePath();$previous=config()->all();
        $dir=base_path('../output/dc11-encrypted-fixture-'.bin2hex(random_bytes(5)));
        File::makeDirectory($dir.'/source/framework',0700,true);File::makeDirectory($dir.'/target/framework',0700,true);
        touch($dir.'/source.sqlite');touch($dir.'/target.sqlite');
        try {
            app()->useStoragePath($dir.'/source');
            config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$dir.'/source.sqlite','production.backup_root'=>$dir.'/archives','synthetic.document_root'=>$dir.'/source/documents']);DB::purge();
            $this->assertSame(0,Artisan::call('migrate',['--force'=>true]));
            $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();
            $category=$this->postJson('/api/categories',['name'=>'DC11 fixture'])->assertCreated()->json();
            $warehouse=$this->postJson('/api/warehouses',['name'=>'DC11 fixture warehouse','code'=>'DC11'])->assertCreated()->json();
            $this->postJson('/api/products',['name'=>'DC11 fixture product','sku'=>'DC11-CIPHER','category_id'=>$category['id'],'default_warehouse_id'=>$warehouse['id'],'quantity'=>5,'unit'=>'pcs','price'=>5,'purchase_price'=>2,'min_quantity'=>1])->assertCreated();
            app()->forgetInstance(DocumentStorageProvider::class);
            $this->getJson('/api/documents/config')->assertOk();
            $this->post('/api/documents',['title'=>'Synthetic recovery witness','document_type_id'=>DocumentType::firstOrFail()->id,'file'=>UploadedFile::fake()->createWithContent('witness.txt','DC11 synthetic document bytes; not a real company statement.')],['Accept'=>'application/json'])->assertCreated();
            \App\Models\PilotProfile::create(['status'=>'active']);
            \App\Models\PilotEvidence::create(['kind'=>'source_review','observed_on'=>today(),'outcome'=>'confirmed','prepared_by'=>Auth::id(),'document_id'=>DB::table('documents')->value('id'),'checks'=>[['name'=>'Synthetic pre-loss observation','result'=>'confirmed']]]);
            $service=app(InstallationBackupService::class);$this->assertTrue($service->reconcile()['healthy']);
            $identity=app(\App\Services\Commercial\InstallationIdentity::class)->id();$key=config('app.key');
            $backup=$service->create('dc11-synthetic-recovery-only');$archive=$dir.'/archives/'.$backup['file'];$payload=$service->verify($archive,'dc11-synthetic-recovery-only');
            $this->assertNotEmpty($payload['files']);$this->assertSame('NOT_VERIFIED',$service->recoveryStatus()['status'],'Creating/verifying an archive is not restoration.');
            try {$service->verify($archive,'incorrect-passphrase');$this->fail('Wrong passphrase accepted');}catch(\RuntimeException $e){$this->assertStringContainsString('No data was restored',$e->getMessage());}
            $damaged=json_decode(file_get_contents($archive),true);$damaged['ciphertext'][0]=$damaged['ciphertext'][0]==='A'?'B':'A';file_put_contents($dir.'/damaged.aimsinstall',json_encode($damaged));
            try {$service->verify($dir.'/damaged.aimsinstall','dc11-synthetic-recovery-only');$this->fail('Damaged archive accepted');}catch(\RuntimeException $e){$this->assertStringContainsString('No data was restored',$e->getMessage());}
            try {$service->restore($archive,'dc11-synthetic-recovery-only');$this->fail('Nonempty target accepted');}catch(\RuntimeException $e){$this->assertStringContainsString('separate empty installation',$e->getMessage());}
            Auth::forgetUser();DB::purge();app()->useStoragePath($dir.'/target');config(['database.connections.sqlite.database'=>$dir.'/target.sqlite','synthetic.document_root'=>$dir.'/target/documents']);app()->forgetInstance(DocumentStorageProvider::class);
            config(['app.key'=>'base64:'.base64_encode(random_bytes(32))]);app()->forgetInstance('encrypter');
            try {$service->restore($archive,'dc11-synthetic-recovery-only');$this->fail('Wrong application key accepted');}catch(\RuntimeException $e){$this->assertStringContainsString('separately escrowed original APP_KEY',$e->getMessage());}
            $this->assertSame([],\Illuminate\Support\Facades\Schema::getTables());
            config(['app.key'=>$key]);app()->forgetInstance('encrypter');
            $restored=$service->restore($archive,'dc11-synthetic-recovery-only');
            $this->assertSame('restored_and_reconciled',$restored['status']);$this->assertSame('VERIFIED',$service->recoveryStatus()['status']);
            $this->assertSame($identity,app(\App\Services\Commercial\InstallationIdentity::class)->id());
            $this->assertSame(1,DB::table('documents')->count());$this->assertEquals(5,DB::table('products')->value('quantity'));$this->assertNull(DB::table('users')->value('api_token'));
            $this->assertSame('paused',DB::table('pilot_profiles')->value('status'));$this->assertEquals(1,DB::table('pilot_evidence')->value('is_historical'));
            foreach($payload['files'] as $path=>$entry){[$label,$relative]=explode('/',$path,2);$root=['documents'=>$dir.'/target/documents','private'=>$dir.'/target/app/private','public'=>$dir.'/target/app/public'][$label];$this->assertSame($entry['sha256'],hash_file('sha256',$root.'/'.$relative));}
            $this->assertSame([],\Illuminate\Support\Facades\Schema::getConnection()->select('PRAGMA foreign_key_check'));
            $proof=$service->recoveryStatus();
            // Even a genuine successful fixture receipt cannot certify another database/key.
            copy($dir.'/target/framework/aims-backup-health.json',$dir.'/source/framework/aims-backup-health.json');DB::purge();app()->useStoragePath($dir.'/source');config(['database.connections.sqlite.database'=>$dir.'/source.sqlite']);
            $this->assertSame('NOT_VERIFIED',$service->recoveryStatus()['status']);
            file_put_contents(base_path('../output/dc11-encrypted-fixture.json'),json_encode(['synthetic_fixture_only'=>true,'source_company_certified'=>false,'archive_integrity'=>true,'decryption'=>true,'wrong_passphrase_denied'=>true,'tamper_denied'=>true,'wrong_application_key_denied'=>true,'nonempty_restore_denied'=>true,'database_restored'=>true,'key_identity_preserved'=>true,'documents_verified'=>$restored['attachments'],'business_reconciled'=>true,'scope_bound_receipt'=>true,'restore_stages'=>$proof['restore_stages']],JSON_PRETTY_PRINT));
        } finally {
            Auth::forgetUser();DB::purge();config()->set($previous);app()->useStoragePath($originalStorage);app()->forgetInstance('encrypter');app()->forgetInstance(DocumentStorageProvider::class);
        }
    }
}
