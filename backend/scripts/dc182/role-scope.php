<?php
require __DIR__.'/bootstrap.php';
$rows=[];
foreach(['primary','sales','purchasing','warehouse','accountant','hr','read_only'] as $role){
 $token=dc17Login($role);$r=dc17Request('GET','me',[],$token);$u=$r['body']['user']??$r['body'];
 $rows[$role]=['http'=>$r['status'],'role'=>$u['role']??null,'permissions'=>$u['permissions']??[],'capabilities'=>$u['commercial']['capabilities']??null];
}
dc17Evidence('role-scope',$rows);echo 'Permission scopes saved without credentials.'.PHP_EOL;
