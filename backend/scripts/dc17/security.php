<?php
require __DIR__.'/bootstrap.php';
$results=[];$hr=dc17Login('hr');
foreach(['products?per_page=1','products?reference_only=1','products/4','products/lookup?sku=DC16-P004','products/export','shelves/A1/products','inventory/products/4','product-suppliers','suppliers','entity-context/product/4'] as $path){$r=dc17Request('GET',$path,[],$hr);$results[$path]=['http'=>$r['status'],'pass'=>$r['status']===403];}
$r=dc17Request('GET','search?q=Milano',[],$hr);$results['search']=['http'=>$r['status'],'pass'=>$r['status']===200&&empty($r['body']['products'])];
$warehouse=dc17Login('warehouse');$r=dc17Request('GET','products/4',[],$warehouse);$p=$r['body']['product']??[];
$results['warehouse']=['http'=>$r['status'],'pass'=>$r['status']===200&&isset($p['inventory'])&&!array_key_exists('purchase_price',$p)&&!array_key_exists('weighted_average_cost',$p)&&!array_key_exists('inventory_value',$p)];
$sales=dc17Login('sales');$r=dc17Request('GET','products?reference_only=1&per_page=1',[],$sales);$p=$r['body']['data'][0]??[];
$results['sales-reference']=['http'=>$r['status'],'pass'=>$r['status']===200&&isset($p['selling_price'])&&!array_key_exists('quantity',$p)&&!array_key_exists('purchase_price',$p)];
$owner=dc17Login('primary');$r=dc17Request('GET','products/4',[],$owner);$results['owner-cost']=['pass'=>$r['status']===200&&isset($r['body']['product']['purchase_price'])];
$r=dc17Request('GET','products/302',[],$owner);$results['tenant']=['http'=>$r['status'],'pass'=>$r['status']===404];
dc17Evidence('security',['checks'=>$results,'all_passed'=>!in_array(false,array_column($results,'pass'),true),'business_writes'=>false]);
echo json_encode(array_map(fn($r)=>$r['pass'],$results))."\n";
if(in_array(false,array_column($results,'pass'),true))exit(2);
