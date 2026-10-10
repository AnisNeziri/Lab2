<?php
if(getenv('CI')!=='true'||getenv('AIMS_CERTIFICATION_SERVER')!=='1'||getenv('DB_DATABASE')!=='aims_ci_server')throw new RuntimeException('Ephemeral CI server fixture only.');
require dirname(__DIR__).'/backend/vendor/autoload.php';
$app=require dirname(__DIR__).'/backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.connections.mysql.database')!=='aims_ci_server'||config('database.connections.mysql.host')!=='127.0.0.1')throw new RuntimeException('Resolved CI server database mismatch.');
app(Database\Seeders\RolePermissionSeeder::class)->run(preserveExisting:true);
$company=App\Models\Company::create(['name'=>'PR1 ephemeral server certification','address'=>'CI fixture only']);
$user=App\Models\User::create(['company_id'=>$company->id,'name'=>'Certification owner','email'=>'owner@aims-ci.invalid','password'=>bin2hex(random_bytes(24)),'role'=>'admin','email_verified_at'=>now(),'is_active'=>true,'must_change_password'=>false]);
App\Models\UserRole::create(['user_id'=>$user->id,'role_id'=>App\Models\Role::where('slug','admin')->value('id'),'assigned_at'=>now()]);
echo 'Ephemeral server owner provisioned; no credentials written to output.'.PHP_EOL;
