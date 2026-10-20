<?php
namespace App\Services;
use Symfony\Component\Process\Process;
class LocalSupplyOptimizer {
 public function solve(array $payload, ?callable $cancelled = null): array {
  $python=config('inventory_intelligence.python','python');
  // One thread per bounded worker prevents nested BLAS pools consuming a small server.
  $env=array_fill_keys(['OPENBLAS_NUM_THREADS','OMP_NUM_THREADS','MKL_NUM_THREADS','NUMEXPR_NUM_THREADS'],'1');
  $p=new Process([$python,'-I',base_path('ml/supply_optimizer.py')],base_path(),$env,json_encode($payload,JSON_THROW_ON_ERROR),25);
  try {
   if($cancelled && $cancelled())throw new \RuntimeException('Optimization cancelled.');
   $p->start();
   while($p->isRunning()){
    $p->checkTimeout();
    if($cancelled && $cancelled())throw new \RuntimeException('Optimization cancelled.');
    usleep(100000);
   }
   $r=json_decode(trim($p->getOutput()),true);
   if(!$p->isSuccessful()||!isset($r['plans']))throw new \RuntimeException($r['error']??'Local optimizer unavailable. Install the pinned SciPy runtime; no cloud fallback is used.');
   return $r;
  } finally { if($p->isRunning())$p->stop(0.1); }
 }
}
