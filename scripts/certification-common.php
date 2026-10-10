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

/** Executed only after the owning script has restored its guarded scratch B. */
function certificationBusinessWorkflows(App\Models\User $owner): array
{
    Illuminate\Support\Facades\Auth::setUser($owner);
    // Restore deliberately disables all channels. Explicitly resume only the
    // internal manual channel in this isolated fixture; external channels stay off.
    App\Models\OrderChannel::where('company_id',$owner->company_id)->where('type','manual')->where('name','Manual')->update(['enabled'=>true]);
    $product=App\Models\Product::where('quantity','>',10)->where('tracking_mode','none')->whereHas('warehouseStock',fn($q)=>$q->where('quantity','>',5)->whereNotNull('location_id'))->firstOrFail();
    $stock=App\Models\WarehouseStock::where('product_id',$product->id)->where('quantity','>',5)->whereNotNull('location_id')->firstOrFail();
    $customer=App\Models\Customer::where('current_debt','>',10)->firstOrFail();
    $key='pr1-populated-'.bin2hex(random_bytes(6));
    $movementData=['product_id'=>$product->id,'warehouse_id'=>$stock->warehouse_id,'location_id'=>$stock->location_id,'source_type'=>'manual_adjustment','type'=>'in','quantity'=>1,'movement_code'=>'manual_adjustment_in','reason'=>'PR1 populated certification','idempotency_key'=>$key.'-stock'];
    $movements=app(App\Services\StockMovementService::class);
    $movement=$movements->store($movementData);
    if($movements->store($movementData)->id!==$movement->id)throw new RuntimeException('Populated stock retry duplicated its movement.');
    $account=App\Models\FinancialAccount::where('is_active',true)->where('currency','EUR')->firstOrFail();
    $paymentData=['amount'=>'1.00','transaction_date'=>App\Support\CompanyClock::today()->toDateString(),'financial_account_id'=>$account->id,'idempotency_key'=>$key.'-payment'];
    $payments=app(App\Services\CustomerDebtService::class);$payment=$payments->recordPayment($customer,$paymentData);
    if($payments->recordPayment($customer,$paymentData)->id!==$payment->id)throw new RuntimeException('Populated payment retry duplicated its ledger.');
    $order=App\Models\PurchaseOrder::whereIn('status',['ordered','partially_received'])->whereHas('items',fn($q)=>$q->whereColumn('quantity','>','received_quantity'))->with('items')->firstOrFail();
    $item=$order->items->first(fn($i)=>$i->quantity>$i->received_quantity);
    $receiptData=['items'=>[['id'=>$item->id,'quantity'=>1]],'idempotency_key'=>$key.'-receipt'];
    $receiptCount=App\Models\GoodsReceipt::count();$procurement=app(App\Services\PurchaseOrderService::class);
    $procurement->receive($order,$receiptData);$procurement->receive($order->fresh(),$receiptData);
    if(App\Models\GoodsReceipt::count()!==$receiptCount+1)throw new RuntimeException('Populated receipt retry duplicated its receipt.');
    $accounting=app(App\Services\AccountingService::class);$accounts=collect($accounting->accounts())->keyBy('code');
    $journal=$accounting->createDraft(['posting_date'=>App\Support\CompanyClock::today()->toDateString(),'description'=>'PR1 populated journal certification','source_key'=>$key.'-journal','lines'=>[['accounting_account_id'=>$accounts['6000']->id,'debit'=>1],['accounting_account_id'=>$accounts['3000']->id,'credit'=>1]]]);
    $accounting->post($journal);$accounting->post($journal->fresh());
    $engine=app(App\Services\OutboundService::class);
    $sale=$engine->create(['customer_id'=>$customer->id,'warehouse_id'=>$stock->warehouse_id,'order_date'=>App\Support\CompanyClock::today()->toDateString(),'payment_type'=>'cash','idempotency_key'=>$key.'-order','items'=>[['product_id'=>$product->id,'unit'=>$product->unit,'quantity'=>1,'unit_price'=>5]]]);
    $intake=App\Models\OrderIntake::where('sales_order_id',$sale->id)->firstOrFail();
    app(App\Services\OrderHubService::class)->confirm($intake,$key.'-confirm');
    app(App\Services\OrderWorkflowService::class)->ready($intake,['verified'=>true,'idempotency_key'=>$key.'-ready']);
    $sale->refresh();$dispatchData=['package_ids'=>$sale->packages()->pluck('id')->all(),'idempotency_key'=>$key.'-dispatch'];
    $engine->action($sale,'dispatch',$dispatchData);$engine->action($sale->fresh(),'dispatch',$dispatchData);
    if($sale->dispatches()->count()!==1)throw new RuntimeException('Populated dispatch retry duplicated its sale.');
    $shipment=App\Models\Shipment::firstOrFail();app(App\Services\ShipmentLogisticsService::class)->details($shipment);
    app(App\Services\InventoryPlanningService::class)->view($product->id);
    app(App\Services\FinancialIntelligenceService::class)->latest();
    app(App\Services\CustomerSalesIntelligenceService::class)->latest();
    $reconciliation=app(App\Services\InstallationBackupService::class)->reconcile();
    if(!$reconciliation['healthy'])throw new RuntimeException('Populated business writes failed inventory or financial reconciliation.');
    return ['stock_movement'=>true,'customer_payment'=>true,'goods_receipt'=>true,'journal_posting'=>true,'order_dispatch'=>true,'repeated_actions_posted_once'=>true,'shipment_and_intelligence_reads'=>true,'post_workflow_reconciliation'=>$reconciliation];
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
