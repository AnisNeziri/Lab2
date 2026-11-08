<?php
require __DIR__.'/api.php';
use Illuminate\Support\Facades\Auth;
$token=dc16Login('hr');$response=dc16Request('GET','products?per_page=1',[],$token);
$p=$response['data'][0]??[];$permissions=app(App\Services\PermissionService::class)->forRole(Auth::user()->role);
dc16Evidence('catalog-permission-boundary',['role'=>Auth::user()->role,'permissions'=>$permissions,'inventory_view'=>in_array('inventory.view',$permissions,true),'sample_product'=>$p,'purchase_cost_exposed'=>array_key_exists('purchase_price',$p),'stock_snapshot_exposed'=>isset($p['inventory'])||isset($p['stock_snapshot'])||isset($p['availability']),'scope'=>'Same-company catalog route lacks inventory.view middleware; whether broad reference access is intended requires policy review. Financial cost visibility is separately observable.']);
echo json_encode(['inventory_view'=>in_array('inventory.view',$permissions,true),'cost_exposed'=>array_key_exists('purchase_price',$p),'keys'=>array_keys($p)])."\n";
