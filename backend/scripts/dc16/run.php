<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\{Artisan,DB};

if($mode==='migrate'){
    $code=Artisan::call('migrate',['--force'=>true,'--no-interaction'=>true]);
    dc16Evidence('migration',['exit_code'=>$code,'output'=>Artisan::output()]);
    echo Artisan::output();exit($code);
}
if($mode==='inventory'){
    $routes=[];
    foreach(app('router')->getRoutes() as $route){
        if(!str_starts_with($route->uri(),'api/'))continue;
        $routes[]=['id'=>hash('sha256',implode('|',$route->methods()).':'.$route->uri()),'feature'=>$route->uri(),'methods'=>$route->methods(),'action'=>$route->getActionName(),'role_preconditions'=>$route->gatherMiddleware(),'pathway'=>'API','status'=>'NOT TESTED','expected_behavior'=>'Requires mapped business scenario and domain authority inspection','observed_result'=>null,'evidence'=>null,'severity'=>null];
    }
    dc16Evidence('route-coverage-inventory',['basis'=>'Route inventory; not yet the complete UI/action coverage denominator','routes'=>$routes]);
    echo count($routes)." API pathways inventoried as NOT TESTED\n";exit;
}
if($mode==='inspect'){
    $tables=DB::select('SHOW TABLES');
    dc16Evidence('installation',['database'=>DB::getDatabaseName(),'version'=>DB::selectOne('SELECT VERSION() v')->v,'tables'=>count($tables),'migrations'=>DB::table('migrations')->count(),'companies'=>DB::table('companies')->count(),'source_company_reused'=>false]);
    echo 'DC16 installation inspected: '.count($tables)." tables\n";exit;
}
throw new RuntimeException('Unknown bounded stage: '.$mode);
