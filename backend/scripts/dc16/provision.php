<?php
/** Deliberate operator setup, never invoked by replay or the application. */
if(($argv[1]??'')!=='--provision-owned-dc16')throw new RuntimeException('Explicit --provision-owned-dc16 is required');
$folder='C:/Users/anisi/.codex/recovery/dc16-20261102';
if(!is_dir($folder))mkdir($folder,0700,true);
$path=$folder.'/credentials.json';
if(!is_file($path))file_put_contents($path,json_encode(['marker'=>'AIMS_DC16_ISOLATED_20261102','database'=>'aims_dc16_20261102','runtime'=>'aims_dc16_runtime','migration'=>'aims_dc16_migration','password'=>bin2hex(random_bytes(24)),'app_key'=>'base64:'.base64_encode(random_bytes(32)),'jwt_secret'=>bin2hex(random_bytes(32)),'owner_password'=>bin2hex(random_bytes(20))],JSON_PRETTY_PRINT));
$c=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
if($c['marker']!=='AIMS_DC16_ISOLATED_20261102'||$c['database']!=='aims_dc16_20261102')throw new RuntimeException('Unrecognized private identity');
$root=json_decode(file_get_contents('C:/Users/anisi/.codex/recovery/dc10-20261101/credentials.json'),true,512,JSON_THROW_ON_ERROR);
$pdo=new PDO('mysql:host=127.0.0.1;port=3320','root',$root['root'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!str_starts_with($pdo->query('SELECT VERSION()')->fetchColumn(),'11.4.13'))throw new RuntimeException('Wrong isolated server');
$exists=$pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='aims_dc16_20261102'")->fetchColumn();
if(!$exists){
    $pdo->exec('CREATE DATABASE aims_dc16_20261102 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE aims_dc16_20261102._dc16_manifest (marker varchar(80) PRIMARY KEY,seed int NOT NULL)');
    $pdo->exec("INSERT INTO aims_dc16_20261102._dc16_manifest VALUES ('AIMS_DC16_ISOLATED_20261102',20261102)");
}
if($pdo->query('SELECT marker FROM aims_dc16_20261102._dc16_manifest')->fetchColumn()!==$c['marker'])throw new RuntimeException('Database ownership mismatch');
foreach(['runtime'=>'SELECT,INSERT,UPDATE,DELETE','migration'=>'ALL PRIVILEGES'] as $kind=>$privileges){
    $name=$c[$kind];if(!in_array($name,['aims_dc16_runtime','aims_dc16_migration'],true))throw new RuntimeException('Unexpected database identity');
    $password=$pdo->quote($c['password']);$pdo->exec("CREATE USER IF NOT EXISTS '$name'@'127.0.0.1' IDENTIFIED BY $password");
    $pdo->exec("GRANT $privileges ON aims_dc16_20261102.* TO '$name'@'127.0.0.1'");
}
echo "Owned isolated schema and scoped identities provisioned; no existing business schema changed.\n";
