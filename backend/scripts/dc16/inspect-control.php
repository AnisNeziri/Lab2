<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
foreach(['sales_orders','purchase_orders','goods_receipts','outbound_dispatches','warehouse_stock'] as $table){
 echo $table.': '.json_encode(DB::table($table)->where('company_id',3)->get())."\n";
}
