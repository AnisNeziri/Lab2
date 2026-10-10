<?php
namespace App\Services;
use Illuminate\Support\Arr;
// Read-only dashboard projection. All values come from the existing authoritative result.
final class WorkspaceSummaryPresentation {
 public static function finance(array $data): array {
  $summary=Arr::only($data,['state','id','as_of','evidence_cutoff','stale','read_only','reason','limitations']);
  $summary['forecast']['currencies']=[];
  foreach($data['forecast']['currencies']??[] as $currency=>$forecast)$summary['forecast']['currencies'][$currency]=Arr::only($forecast,['expected_closing_cash']);
  return $summary;
 }
 public static function customers(array $data): array {
  $summary=Arr::only($data,['state','id','as_of','stale','read_only','reason','limitations']);
  $summary['evidence']['opportunities']=array_map(fn($row)=>Arr::only($row,['key','customer','customer_id','product','product_id','kind']),array_slice($data['evidence']['opportunities']??[],0,4));
  return $summary;
 }
}
