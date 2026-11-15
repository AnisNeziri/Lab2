<?php

require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e::class.': '.$e->getMessage().PHP_EOL);exit(1);});
$db=config('database.connections.sqlite.database');
if(!app()->environment('e2e')||config('database.default')!=='sqlite'||realpath($db)!==realpath(base_path('database/e2e.sqlite')))throw new RuntimeException('DC2 browser fixtures require the isolated e2e.sqlite database.');
$company=App\Models\Company::firstOrCreate(['name'=>'DC2 Browser Accounting Company'],['address'=>'Synthetic browser test company','base_currency'=>'EUR','timezone'=>'UTC']);
$password=getenv('AIMS_E2E_WORKDAY_PASSWORD');
if(!is_string($password)||!preg_match('/\A[a-f0-9]{48}\z/',$password))throw new RuntimeException('An ephemeral DC2 fixture password is required.');
$accountant=App\Models\User::updateOrCreate(['email'=>'dc2-finance@e2e.example'],['company_id'=>$company->id,'name'=>'DC2 Test Accountant','password'=>bcrypt($password),'role'=>'accountant','is_active'=>true,'email_verified_at'=>now(),'must_change_password'=>false,'temporary_password_consumed'=>false]);
App\Models\User::updateOrCreate(['email'=>'dc2-manager@e2e.example'],['company_id'=>$company->id,'name'=>'DC2 Test Manager','password'=>bcrypt($password),'role'=>'manager','is_active'=>true,'email_verified_at'=>now(),'must_change_password'=>false,'temporary_password_consumed'=>false]);
Illuminate\Support\Facades\Auth::setUser($accountant);
app(App\Services\AccountingService::class)->initialize();
echo "DC2 isolated browser finance company prepared.\n";
