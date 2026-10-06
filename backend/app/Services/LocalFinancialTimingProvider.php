<?php
namespace App\Services;
use Symfony\Component\Process\Process;
final class LocalFinancialTimingProvider {
 public function analyze(array $customers):array {
  $p=new Process([(string)config('inventory_intelligence.python'),'-I',base_path('ml/financial_timing.py')]);$p->setTimeout(15);
  $p->setInput(json_encode(['cutoff'=>now()->toIso8601String(),'minimum_samples'=>config('financial_intelligence.minimum_timing_samples'),'customers'=>array_map(fn($c)=>['id'=>$c['id'],'samples'=>$c['history']['samples']],$customers)],JSON_THROW_ON_ERROR));
  try{$p->mustRun();return ['state'=>'available']+json_decode($p->getOutput(),true,64,JSON_THROW_ON_ERROR);}catch(\Throwable $e){return ['state'=>'unavailable','qualification'=>'Local Python diagnostic unavailable. Deterministic due dates and PHP historical statistics remain usable.'];}
 }
}
