<?php

function certificationAssertBaseline(PDO $database): void
{
    $root=dirname(__DIR__);
    $baseline=json_decode(file_get_contents($root.'/RELEASE.json'),true)['upgrade_baseline'];
    $process=new Symfony\Component\Process\Process(['git','ls-tree','-r','--name-only',$baseline,'backend/database/migrations'],$root);
    $process->mustRun();
    $expected=array_map(fn($file)=>pathinfo($file,PATHINFO_FILENAME),array_filter(explode("\n",trim($process->getOutput()))));sort($expected);
    $actual=$database->query('select migration from migrations order by migration')->fetchAll(PDO::FETCH_COLUMN);sort($actual);
    if($actual!==$expected)throw new RuntimeException('Synthetic source migration history does not match the selected supported upgrade baseline.');
}

function certificationRequest(string $method, string $path, array $data = [], ?string $token = null): array
{
    Illuminate\Support\Facades\Auth::logout();
    $request=Illuminate\Http\Request::create($path,$method,[],[],[],['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json',...($token ? ['HTTP_AUTHORIZATION'=>'Bearer '.$token] : [])],json_encode($data));
    $kernel=app(Illuminate\Contracts\Http\Kernel::class);
    $response=$kernel->handle($request); $body=json_decode($response->getContent(),true); $kernel->terminate($request,$response);
    return ['status'=>$response->getStatusCode(),'body'=>$body];
}

function certificationCriticalRecords(): array
{
    $result=[];
    foreach(['companies','products','warehouse_stock','stock_movements','customers','suppliers','purchase_orders','purchase_order_items','sales_orders','sales_order_items','order_intakes','journal_entries','journal_lines','settings','document_settings','enterprise_decisions','strategic_simulations','shipment_intelligence','financial_intelligence_snapshots','financial_intelligence_policies','decision_learning_records'] as $table) {
        if(!Illuminate\Support\Facades\Schema::hasTable($table))continue;
        $rows=Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson();
        $result[$table]=['count'=>Illuminate\Support\Facades\DB::table($table)->count(),'sha256'=>hash('sha256',$rows)];
    }
    $result['user_preferences']=hash('sha256',Illuminate\Support\Facades\DB::table('users')->orderBy('id')->get(['id','company_id','email','password','preferences'])->toJson());
    $result['document_associations']=hash('sha256',Illuminate\Support\Facades\DB::table('documents')->orderBy('id')->get(['id','company_id','title','current_version'])->toJson());
    return $result;
}

function certificationTenantMatrix(int $sourceCompany): array
{
    $owner=App\Models\User::where('company_id',$sourceCompany)->where('role','admin')->firstOrFail();
    $sourceToken=app(App\Services\JwtService::class)->createAccessToken($owner);
    Illuminate\Support\Facades\Auth::logout();
    $company=App\Models\Company::create(['name'=>'PR1 isolated second test company','address'=>'Synthetic certification only']);
    $user=App\Models\User::create(['company_id'=>$company->id,'name'=>'PR1 test owner','email'=>'pr1-owner@certification.test','password'=>bin2hex(random_bytes(24)),'role'=>'admin','is_active'=>true,'email_verified_at'=>now(),'must_change_password'=>false]);
    $role=App\Models\Role::where('slug','admin')->firstOrFail();
    App\Models\UserRole::create(['user_id'=>$user->id,'role_id'=>$role->id,'assigned_at'=>now()]);
    $token=app(App\Services\JwtService::class)->createAccessToken($user);
    $routes=['products'=>['products','/api/products/'],'customers'=>['customers','/api/customers/'],'suppliers'=>['suppliers','/api/suppliers/'],'orders'=>['order_intakes','/api/order-hub/intakes/'],'purchase_orders'=>['purchase_orders','/api/purchase-orders/'],'shipments'=>['shipments','/api/shipments/'],'documents'=>['documents','/api/documents/'],'financial_records'=>['journal_entries','/api/accounting/journals/'],'decisions'=>['enterprise_decisions','/api/analytics/decisions/'],'simulations'=>['strategic_simulations','/api/strategic-simulation/']];
    $result=[];
    foreach($routes as $label=>[$table,$prefix]) {
        $id=Illuminate\Support\Facades\DB::table($table)->where('company_id',$sourceCompany)->value('id');
        if(!$id)throw new RuntimeException('Populated tenant fixture missing '.$label);
        $owned=certificationRequest('GET',$prefix.$id,[],$sourceToken);
        if($owned['status']!==200)throw new RuntimeException('Owner cannot access tenant fixture '.$label.' status '.$owned['status']);
        $response=certificationRequest('GET',$prefix.$id,[],$token);
        if(!in_array($response['status'],[403,404],true))throw new RuntimeException('Cross-company route failed: '.$label.' status '.$response['status']);
        $result[$label]=$response['status'];
    }
    $response=certificationRequest('PUT','/api/settings/workspace',['revision'=>0,'company_id'=>$sourceCompany,'user_id'=>1,'dashboard'=>['version'=>1,'widgets'=>[]]],$token);
    if($response['status']!==422)throw new RuntimeException('Workspace ownership injection accepted.');
    $result['workspace_settings']=$response['status'];
    Illuminate\Support\Facades\Auth::forgetUser();
    return $result;
}
