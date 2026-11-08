<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
if((int)DB::table('_dc16_checkpoints')->where('name','history')->value('completed_day')!==364)throw new RuntimeException('Year incomplete');
$service=app(App\Services\InstallationBackupService::class);
$result=$service->reconcile();
dc16Evidence('installation-reconciliation',$result);
echo json_encode(['healthy'=>$result['healthy'],'checks'=>$result['checks']])."\n";
