<?php
namespace App\Observers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Cache,DB};
/** Cheap after-commit marker only. Threshold reconciliation happens in a bounded worker. */
class SupplyOptimizationObserver {
 public function saved(Model $m):void {
  if(!$m->wasRecentlyCreated&&!$m->wasChanged(['quantity','available_quantity','reserved_quantity','blocked_quantity','quarantine_quantity','lifecycle_status','is_active','unit','min_quantity','safety_stock','reorder_point','replenishment_review_days','purchase_price','pack_size','minimum_order_quantity','usual_lead_time_days','exchange_rate_to_base','status','expected_at','eta','received_base_quantity','base_quantity','value','settings']))return;
  $this->mark($m);
 }
 public function deleted(Model $m):void {$this->mark($m);}
 private function mark(Model $m):void {$company=$m->company_id;if(!$company)return;$fn=static function()use($company){try{Cache::put('optimizer-dirty:'.$company,(string)\Illuminate\Support\Str::uuid(),86400);}catch(\Throwable){\Illuminate\Support\Facades\Log::warning('Optimizer dirty marker unavailable; confirmation still revalidates live evidence.',['company_id'=>$company]);}};DB::transactionLevel()>0?DB::afterCommit($fn):$fn();}
}
