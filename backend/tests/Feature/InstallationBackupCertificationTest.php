<?php

namespace Tests\Feature;

use App\Services\InstallationBackupService;
use Illuminate\Support\Facades\{Artisan, DB, File};
use Tests\TestCase;

class InstallationBackupCertificationTest extends TestCase
{
    public function test_verified_retention_protects_manual_backups_and_overlap_preserves_known_good_archives(): void
    {
        $directory=storage_path('app/pr1-backup-test-'.bin2hex(random_bytes(8)));
        File::makeDirectory($directory.'/framework',0700,true);
        touch($directory.'/empty.sqlite');
        $originalStorage=app()->storagePath();
        $previous=config()->all();
        $lock=null;
        try {
            app()->useStoragePath($directory);
            config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$directory.'/empty.sqlite','production.backup_root'=>$directory.'/backups','production.backup_keep'=>2,'synthetic.document_root'=>$directory.'/documents']);
            DB::purge('sqlite');
            $this->assertSame(0,Artisan::call('migrate',['--force'=>true]));
            $service=app(InstallationBackupService::class);$key=bin2hex(random_bytes(24));
            $manual=$service->create($key);
            for($i=0;$i<3;$i++)$service->create($key,true);
            $this->assertCount(2,glob($directory.'/backups/scheduled-*.aimsinstall'));
            $this->assertFileExists($directory.'/backups/'.$manual['file']);
            $service->verify($directory.'/backups/'.$manual['file'],$key);
            $before=glob($directory.'/backups/*.aimsinstall');
            $lock=fopen($directory.'/framework/aims-installation-backup.lock','c');
            $this->assertTrue(flock($lock,LOCK_EX|LOCK_NB));
            try { $service->create($key,true);$this->fail('Overlapping backup accepted.'); }
            catch(\RuntimeException $error) { $this->assertStringContainsString('Another installation backup',$error->getMessage()); }
            $this->assertSame($before,glob($directory.'/backups/*.aimsinstall'));
            $this->assertSame([],glob($directory.'/backups/*.partial'));
            flock($lock,LOCK_UN);fclose($lock);$lock=null;
            $lastSuccess=json_decode(file_get_contents($directory.'/framework/aims-backup-health.json'),true)['last_success_at'];
            try { $service->create('short');$this->fail('Weak backup passphrase accepted.'); }
            catch(\RuntimeException $error) { $this->assertStringContainsString('passphrase',$error->getMessage()); }
            $health=json_decode(file_get_contents($directory.'/framework/aims-backup-health.json'),true);
            $this->assertSame($lastSuccess,$health['last_success_at']);
            $this->assertNotEmpty($health['last_failure_at']);
            $this->assertSame($before,glob($directory.'/backups/*.aimsinstall'));
        } finally {
            if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
            DB::purge('sqlite');config()->set($previous);app()->useStoragePath($originalStorage);
            File::deleteDirectory($directory);
        }
    }
}
