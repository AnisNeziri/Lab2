<?php
// Isolated browser-test profiles only. Never usable against the web database.
$db=realpath(__DIR__.'/../storage/app/synthetic/aims-pm3-20261006.sqlite');
if(!$db||!is_file($db.'.report.json'))throw new RuntimeException('Completed PM3 workspace required.');
putenv('APP_ENV=synthetic');putenv('DB_CONNECTION=sqlite');putenv('DB_DATABASE='.$db);putenv('DB_URL=');putenv('CACHE_STORE=array');putenv('MAIL_MAILER=array');putenv('QUEUE_CONNECTION=sync');putenv('BROADCAST_CONNECTION=log');putenv('APP_CONFIG_CACHE='.__DIR__.'/../storage/app/synthetic/uncached-config.php');
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='sqlite'||realpath(config('database.connections.sqlite.database'))!==$db)throw new RuntimeException('Wrong database.');
$owner=App\Models\User::where('email','owner@aims-demo.test')->firstOrFail();
foreach(['sales','warehouse','purchasing'] as $job){
    App\Models\User::firstOrCreate(['email'=>$job.'@aims-demo.test'],['company_id'=>$owner->company_id,'name'=>'Synthetic '.ucfirst($job).' Employee','role'=>'staff','password'=>'AimsDemo.Test.2026!','is_active'=>true,'email_verified_at'=>now(),'preferences'=>['language'=>'en','theme'=>'light']]);
}
echo "PM4 synthetic employee profiles ready. Existing users and passwords unchanged.\n";
