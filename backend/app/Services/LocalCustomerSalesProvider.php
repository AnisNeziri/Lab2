<?php
namespace App\Services;
use Symfony\Component\Process\Process;
final class LocalCustomerSalesProvider {
 public function analyze(array $profiles):array {
  $p=new Process([(string)config('inventory_intelligence.python'),'-I',base_path('ml/customer_sales.py')]);$p->setTimeout(15);$p->setInput(json_encode(['minimum_purchases'=>config('customer_intelligence.minimum_purchases'),'customers'=>array_map(fn($c)=>['id'=>$c['id'],'dates'=>$c['diagnostic_dates']],$profiles)],JSON_THROW_ON_ERROR));
  try{$p->mustRun();return ['state'=>'available']+json_decode($p->getOutput(),true,64,JSON_THROW_ON_ERROR);}catch(\Throwable $e){return ['state'=>'unavailable','qualification'=>'Local Python diagnostic unavailable; the PHP statistical baseline remains functional.'];}
 }
}
