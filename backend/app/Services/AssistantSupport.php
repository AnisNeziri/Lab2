<?php
namespace App\Services;

use App\Models\PilotFeedback;
use App\Support\{PilotPrivacy,Release};
use Illuminate\Support\Facades\{Cache,Auth};
use Illuminate\Support\Str;

/** Existing pilot inbox is the receipt authority. No external transport or raw screenshots. */
final class AssistantSupport
{
    private function key(string $id):string{return 'assistant-support:'.Auth::user()->company_id.':'.Auth::id().':'.$id;}
    public function prepare(array $input):array {
        abort_unless(Auth::user()?->company_id,403);
        $v=validator($input,['title'=>'required|string|max:120','description'=>'required|string|max:1000','page'=>'required|string|max:500','steps'=>'nullable|string|max:500'])->validate();
        $id=(string)Str::uuid();$v=array_map(fn($s)=>PilotPrivacy::text($s),$v);$v['page']=PilotPrivacy::page($v['page']);
        $v+=['release'=>Release::version(),'created_at'=>now()->toIso8601String(),'uncertainty'=>'User reported; cause not verified.','delivery'=>'Internal company administrator inbox only. External developer delivery is not configured.'];
        Cache::store('file')->put($this->key($id),$v,1800);
        return ['id'=>$id,'status'=>'draft','preview'=>$v,'attachment_included'=>false];
    }
    public function submit(string $id,array $input):array {
        abort_unless(Auth::user()?->company_id,403);
        validator(['id'=>$id]+$input,['id'=>'required|uuid','confirmed'=>'required|accepted'])->validate();
        $cache=Cache::store('file');$key=$this->key($id);
        return $cache->lock($key.':lock',10)->block(3,function()use($cache,$key,$id){
            $v=$cache->get($key);abort_unless($v,404);
            if(isset($v['receipt']))return $v['receipt'];
            // Stable database identity also survives a crash after insert, before cache update.
            $reference='AIMS-'.substr(hash('sha256',$id),0,16);
            $row=PilotFeedback::where('user_id',Auth::id())->where('reference',$reference)->first();
            $row??=app(PilotService::class)->feedback(['reference'=>$reference,'category'=>'error','page'=>$v['page'],'description'=>implode("\n",[$v['title'],$v['description'],$v['steps']??'','Version: '.$v['release'],$v['uncertainty']])]);
            $receipt=['id'=>$row->id,'status'=>'received_internal','external_delivery'=>false];
            $v['receipt']=$receipt;$cache->put($key,$v,1800);
            app(AnalyticsDataService::class)->audit('assistant.support_submitted',['feedback_id'=>$row->id]);return $receipt;
        });
    }
    public function status(int $id):array {
        abort_unless(Auth::user()?->company_id,403);
        $row=PilotFeedback::findOrFail($id);abort_unless($row->user_id===Auth::id()||Auth::user()->role==='admin',403);
        app(AnalyticsDataService::class)->audit('assistant.support_viewed',['feedback_id'=>$id]);
        return ['id'=>$id,'status'=>match($row->status){'resolved'=>'resolved','in_progress'=>'in_review',default=>'received_internal'},'external_delivery'=>false];
    }
}
