<?php
namespace App\Services;
use Symfony\Component\Process\Process;
class LocalSupplyOptimizer {
 public function solve(array $payload):array {
  $python=config('inventory_intelligence.python','python');
  $p=new Process([$python,'-I',base_path('ml/supply_optimizer.py')],base_path(),null,json_encode($payload,JSON_THROW_ON_ERROR),25);
  $p->run();$r=json_decode(trim($p->getOutput()),true);
  if(!$p->isSuccessful()||!isset($r['plans']))throw new \RuntimeException($r['error']??'Local optimizer unavailable. Install the pinned SciPy runtime; no cloud fallback is used.');
  return $r;
 }
}
