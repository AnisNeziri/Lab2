<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
$results=[];
foreach(['system_grants'=>'SELECT COUNT(*) FROM mysql.user','prior_test_database'=>'SELECT COUNT(*) FROM aims_dc12_rehearsal.companies'] as $name=>$sql){
    try{DB::select($sql);$results[$name]=['status'=>'FAIL','reason'=>'Inappropriate database read was allowed'];}
    catch(Illuminate\Database\QueryException $e){$code=(int)($e->errorInfo[1]??0);$results[$name]=['status'=>in_array($code,[1044,1142],true)?'PASS':'BLOCKED','driver_code'=>$code,'reason'=>'Read denied; no source data returned'];}
}
$grants=DB::select("SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE TABLE_SCHEMA=?",[DB::getDatabaseName()]);
dc16Evidence('runtime-boundary',['identity'=>DB::selectOne('SELECT CURRENT_USER() identity')->identity,'database'=>DB::getDatabaseName(),'schema_privileges'=>array_map(fn($g)=>$g->PRIVILEGE_TYPE,$grants),'negative_reads'=>$results,'core_CRUD'=>'Exercised through registration, opening and historical business authorities','schema_mutations_attempted'=>false]);
echo json_encode($results)."\n";
