<?php
require __DIR__.'/api.php';
use Illuminate\Support\Facades\DB;
if((int)DB::table('_dc16_checkpoints')->where('name','history')->value('completed_day')!==364)throw new RuntimeException('Year incomplete');
$token=dc16Login('primary');$rows=[];
foreach(['dashboard','operating-brief','products?per_page=20','inventory/control-center','sales-orders?per_page=20','accounting/overview','action-center','search?q=Milano','automation-health','finance/overview'] as $path){
 foreach(['first','repeat'] as $sample){
  $before=count(file($dc16Root.'/storage/evidence/requests.jsonl'));
  $body=dc16Request('GET',$path,[],$token,[],[200,403,422,500]);
  $entries=file($dc16Root.'/storage/evidence/requests.jsonl',FILE_IGNORE_NEW_LINES);$entry=json_decode(end($entries),true);
  $rows[]=['sample'=>$sample,'path'=>$path,'measurement'=>$entry,'semantic_pass'=>false,'response_keys'=>array_keys($body)];
 }
}
dc16Evidence('performance',['dataset_days'=>365,'samples'=>$rows,'qualification'=>'Local sequential requests, first/repeat in one PHP process with array cache. Not a production load test or guaranteed cold/warm infrastructure cache. HTTP success alone does not prove semantic correctness.']);echo "Bounded DC7 latency/query/payload samples saved.\n";
