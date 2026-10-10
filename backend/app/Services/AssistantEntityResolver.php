<?php

namespace App\Services;

use App\Models\{Product,Customer,Supplier,Shipment,PurchaseOrder,SalesOrder,Warehouse};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Permission-aware bounded fuzzy lookup. A close competing match always requires selection. */
final class AssistantEntityResolver
{
    public function resolve(string $term, ?string $type = null): array
    {
        abort_unless(Auth::user()?->company_id,403);
        $definitions = [
            'product'=>[Product::class,'inventory.view',['name','sku','barcode'],'name','/products?product='],
            'customer'=>[Customer::class,'debts.view',['name','email','fiscal_number'],'name','/customer-debts?customer='],
            'supplier'=>[Supplier::class,'supplier_catalogue.view',['name','email'],'name','/suppliers?supplier='],
            'shipment'=>[Shipment::class,'shipments.view',['vessel_name','tracking_number','tracking_reference','mmsi','imo','origin_port','destination_port'],'vessel_name','/shipments/my-shipments?shipment='],
            'purchase_order'=>[PurchaseOrder::class,'purchase_orders.view',['po_number'],'po_number','/purchase-orders?po='],
            'sales_order'=>[SalesOrder::class,'fulfillment.view',['order_number'],'order_number','/fulfillment?order='],
            'warehouse'=>[Warehouse::class,'inventory.view',['name','code','address'],'name','/warehouse-operations?warehouse='],
        ];
        $needle = AssistantLanguage::identity($term);
        if (strlen($needle) < 2) return ['entity'=>null,'choices'=>[],'fuzzy'=>false];
        $tokens = preg_split('/\s+/', Str::lower(Str::ascii($term)));
        $grams = [];
        foreach ($tokens as $token) for ($i=0; $i<strlen($token)-1; $i++) $grams[] = substr($token,$i,2);
        $grams = array_slice(array_values(array_unique($grams)),0,18);
        $matches=[];$truncated=false;
        foreach ($definitions as $kind=>[$model,$permission,$fields,$title,$url]) {
            if ($type && $kind !== $type || !app(PermissionService::class)->roleHasPermission(Auth::user()->role,$permission)) continue;
            $rows=$model::query()->where(function ($q) use ($fields,$grams,$term) {
                foreach ($fields as $field) {
                    $q->orWhere($field,'like','%'.addcslashes($term,'%_\\').'%');
                    foreach ($grams as $gram) $q->orWhere($field,'like','%'.addcslashes($gram,'%_\\').'%');
                }
            })->orderBy('id')->limit(201)->get();
            $truncated = $truncated || $rows->count()>200;
            foreach ($rows->take(200) as $row) {
                $score=0;$exact=false;
                foreach ($fields as $field) {
                    $candidate=AssistantLanguage::identity((string)$row->$field);
                    if (!$candidate) continue;
                    if ($candidate===$needle) {$score=1;$exact=true;break;}
                    if (str_contains($candidate,$needle)) $score=max($score,.86);
                    // Damerau distance handles ordinary adjacent-letter transpositions.
                    $distance=levenshtein($needle,$candidate);
                    for($i=0;$i<strlen($needle)-1;$i++){
                        $swap=$needle;$swap[$i]=$needle[$i+1];$swap[$i+1]=$needle[$i];
                        $distance=min($distance,1+levenshtein($swap,$candidate));
                    }
                    $score=max($score,1-$distance/max(strlen($needle),strlen($candidate)));
                }
                if ($score>=.65) $matches[]=['type'=>$kind,'id'=>$row->id,'title'=>$row->$title?:$row->tracking_number?:$row->tracking_reference,'subtitle'=>$kind==='product'?$row->sku:($kind==='shipment'?($row->origin_port.' → '.$row->destination_port):null),'url'=>$url.$row->id,'score'=>$score,'exact'=>$exact];
            }
        }
        usort($matches,fn($a,$b)=>$b['score']<=>$a['score']);
        $exact=array_values(array_filter($matches,fn($m)=>$m['exact']));
        $best=$matches[0]??null;$next=$matches[1]['score']??0;
        $resolved=!$truncated&&count($exact)===1?$exact[0]:(!$truncated && !$exact && $best && $best['score']>=.8 && $best['score']-$next>=.12?$best:null);
        $clean=fn($m)=>array_diff_key($m,array_flip(['score','exact']));
        return ['entity'=>$resolved?$clean($resolved):null,'choices'=>$resolved?[]:array_map($clean,array_slice($matches,0,6)),'fuzzy'=>$resolved&&!$resolved['exact']];
    }
}
