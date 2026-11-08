<?php
namespace Dc16;
final class ProbeJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
 use \Illuminate\Bus\Queueable;
 public function __construct(public string $id){}
 public function handle():void {
  $root='C:/Users/anisi/.codex/recovery/dc16-20261102/storage/evidence';
  if(\Illuminate\Support\Facades\DB::getDatabaseName()!=='aims_dc16_20261102')throw new \RuntimeException('Wrong probe database');
  file_put_contents($root.'/worker-probe.jsonl',json_encode(['id'=>$this->id,'pid'=>getmypid(),'database'=>\Illuminate\Support\Facades\DB::getDatabaseName()])."\n",FILE_APPEND|LOCK_EX);
 }
}
