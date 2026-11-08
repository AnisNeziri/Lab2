<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
foreach(DB::select('SHOW PROCESSLIST') as $p) echo json_encode(['id'=>$p->Id,'db'=>$p->db,'command'=>$p->Command,'time'=>$p->Time,'state'=>$p->State,'info'=>substr($p->Info??'',0,180)])."\n";
foreach(DB::table('_dc16_checkpoints')->get(['name','completed_day','saved_at']) as $r) echo json_encode($r)."\n";
