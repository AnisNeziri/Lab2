<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('e2e') || config('database.default')!=='sqlite' || basename(config('database.connections.sqlite.database'))!=='dc8-preview.sqlite') throw new RuntimeException('Isolated DC8 preview database required.');
$company=App\Models\Company::factory()->create(['name'=>'DC8 Synthetic Company','timezone'=>'Europe/Budapest','base_currency'=>'EUR']);
$owner=App\Models\User::factory()->create(['name'=>'DC8 Company Owner','email'=>'dc8-owner-'.$company->id.'@e2e.example','password'=>bcrypt('dc8-synthetic-browser-secret'),'role'=>'admin','company_id'=>$company->id,'email_verified_at'=>now(),'is_active'=>true]);
(new Database\Seeders\RolePermissionSeeder)->run(true);
echo json_encode(['company'=>$company->id,'email'=>$owner->email])."\n";
