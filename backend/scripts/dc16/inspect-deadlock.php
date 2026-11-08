<?php
// Read-only diagnostic on the clean isolated instance. Never operates on 3306.
$root=json_decode(file_get_contents('C:/Users/anisi/.codex/recovery/dc10-20261101/credentials.json'),true);
$p=new PDO('mysql:host=127.0.0.1;port=3320','root',$root['root'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!str_starts_with($p->query('SELECT VERSION()')->fetchColumn(),'11.4.13'))throw new RuntimeException('Wrong isolated server');
$status=$p->query('SHOW ENGINE INNODB STATUS')->fetch(PDO::FETCH_ASSOC);
file_put_contents('C:/Users/anisi/.codex/recovery/dc16-20261102/storage/evidence/innodb-deadlock.txt',$status['Status']);
echo "Clean-instance deadlock diagnostics saved privately; no mutation.\n";
