<?php
namespace App\Services;
use Symfony\Component\Process\Process;
use App\Exceptions\ForecastUnavailableException;
final class LocalSupplierLeadProvider {
 public function run(array $payload):array {
  $p=new Process([(string)config('inventory_intelligence.python'),'-I',base_path('ml/supplier_forecast.py')]);$p->setTimeout(config('supplier_intelligence.training_timeout'));$p->setInput(json_encode($payload,JSON_THROW_ON_ERROR));
  try{$p->mustRun();$r=json_decode($p->getOutput(),true,64,JSON_THROW_ON_ERROR);if(!is_array($r))throw new \RuntimeException();return $r;}
  catch(\Throwable $e){throw new ForecastUnavailableException('Local supplier ML unavailable. Measured historical baselines remain available.',0,$e);}
 }
}
