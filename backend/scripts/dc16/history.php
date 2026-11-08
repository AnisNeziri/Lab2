<?php
require __DIR__.'/api.php';require __DIR__.'/Replay.php';
use Illuminate\Support\Facades\{Auth,DB};
$token=dc16Login('primary');dc16Request('GET','me',[],$token);
$dc16Accounts=json_decode(file_get_contents($dc16Root.'/accounts.json'),true);
$dc16Owner=App\Models\User::withoutGlobalScopes()->findOrFail($dc16Accounts['primary']['user_id']);Auth::setUser($dc16Owner);
if(App\Models\OnboardingSession::where('status','locked')->doesntExist())throw new RuntimeException('Valid locked opening required');
$limit=max(1,min(31,(int)($argv[1]??7)));
$generatorHash=hash_file('sha256',__DIR__.'/Replay.php');
if(is_file($dc16Root.'/storage/evidence/replay-blocked.json'))throw new RuntimeException('Replay is held for an unresolved integrity finding; inspect replay-blocked.json');
$lock=DB::selectOne("SELECT GET_LOCK('aims_dc16_replay',0) AS acquired");if(!$lock->acquired)throw new RuntimeException('Another isolated replay is active');
try{
    for($batch=0;$batch<$limit;$batch++){
        $checkpoint=DB::table('_dc16_checkpoints')->where('name','history')->first();$day=($checkpoint?->completed_day??-1)+1;if($day>=365)break;
        $replay=new Dc16Replay();if($checkpoint)$replay->state=json_decode($checkpoint->state,true,512,JSON_THROW_ON_ERROR);
        $start=microtime(true);
        $trace=app(App\Services\Platform\PlatformTrace::class);$trace->begin(null,$dc16Owner->company_id);
        try{
            DB::transaction(function()use($replay,$day){
                $replay->run($day);
                DB::table('_dc16_checkpoints')->updateOrInsert(['name'=>'history'],['completed_day'=>$day,'state'=>json_encode($replay->state,JSON_THROW_ON_ERROR),'saved_at'=>now()]);
            });
        }catch(Throwable $e){
            dc16Evidence('history-failure',['day'=>$day,'date'=>Carbon\CarbonImmutable::parse('2025-11-01')->addDays($day)->toDateString(),'exception'=>get_class($e),'message'=>$e->getMessage(),'validation'=>$e instanceof Illuminate\Validation\ValidationException?$e->errors():null,'trace'=>array_slice($e->getTrace(),0,8),'committed_checkpoint'=>DB::table('_dc16_checkpoints')->where('name','history')->value('completed_day')]);throw $e;
        }
        $metrics=['completed_day'=>$day,'date'=>today()->toDateString(),'days'=>365,'seconds'=>round(microtime(true)-$start,2),'dc7_telemetry'=>$trace->stats(),'phases'=>$replay->state['phase_metrics']??null,'generator_sha256'=>$generatorHash];
        dc16Evidence('history-checkpoint',$metrics);
        file_put_contents($dc16Root.'/storage/evidence/generation-metrics.jsonl',json_encode($metrics)."\n",FILE_APPEND|LOCK_EX);
        echo 'Committed day '.($day+1).'/365 '.today()->toDateString().' ('.round(microtime(true)-$start,2)."s)\n";
        if($day%7===6){
            $controls=app(App\Services\AccountingService::class)->reconciliation(false);
            if(!$controls['all_reconciled']){
                dc16Evidence('replay-blocked',['status'=>'BLOCKED','completed_day'=>$day,'reason'=>'Weekly financial checkpoint differs','controls'=>$controls,'business_records_preserved'=>true]);
                throw new RuntimeException('Weekly financial check failed; replay held with source records preserved');
            }
        }
    }
}finally{DB::select("SELECT RELEASE_LOCK('aims_dc16_replay')");}
