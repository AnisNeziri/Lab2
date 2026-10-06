<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class RunSupplyOptimization implements ShouldQueue {
 use Queueable;
 public int $tries=1;
 public int $timeout=240;
 public function __construct(public int $planId){}
 public function handle():void {app(\App\Services\SupplyOptimizerService::class)->run($this->planId);}
 public function failed(?\Throwable $e):void {\App\Models\SupplyOptimizationPlan::withoutGlobalScopes()->whereKey($this->planId)->whereIn('status',['QUEUED','RUNNING'])->update(['status'=>'FAILED','error'=>'Optimization worker interrupted; create a new plan.']);}
}
