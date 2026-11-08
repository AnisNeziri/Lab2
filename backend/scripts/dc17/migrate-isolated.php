<?php
if(($argv[1]??'')!=='migrate')throw new RuntimeException('Explicit migrate mode required for the owned isolated schema only');
require __DIR__.'/bootstrap.php';
$code=Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true,'--no-interaction'=>true]);
dc17Evidence('migration',['database'=>Illuminate\Support\Facades\DB::getDatabaseName(),'exit'=>$code,'output'=>Illuminate\Support\Facades\Artisan::output(),'historical_closures_populated'=>false]);
echo Illuminate\Support\Facades\Artisan::output();exit($code);
