<?php
namespace App\Services;

use App\Models\EnterpriseDecision;

/** Actual immutable decision versions, not reconstructed historical balances. */
final class AssistantEvidenceService
{
    public function changes(array $input):array
    {
        $service=app(EnterpriseDecisionService::class);$service->authorize();
        $v=validator($input,['decision_id'=>'sometimes|integer|min:1','from'=>'sometimes|date_format:Y-m-d','to'=>'sometimes|date_format:Y-m-d'])->validate();
        $query=EnterpriseDecision::query();
        if(isset($v['decision_id']))$query->whereKey($v['decision_id']);
        else $query->where('generated_at','>=',($v['from']??today()->subDay()->toDateString()).' 00:00:00')->where('generated_at','<=',($v['to']??today()->toDateString()).' 23:59:59');
        $pairs=[];
        foreach($query->latest('id')->limit(10)->get() as $current) {
            $previous=EnterpriseDecision::where('logical_key',$current->logical_key)->where('id','<',$current->id)->latest('id')->first();
            $a=$service->detail($current->id);$b=$previous?$service->detail($previous->id):null;$delta=[];
            if($b)foreach(['severity','confidence','status','recommended.action','recommended.base_quantity','recommended.supplier_name','recommended.base_cost','evidence.forecast.stockout_date','evidence.required_quantity'] as $field) {
                $before=data_get($b,$field);$after=data_get($a,$field);
                if($before!==$after)$delta[]=['field'=>$field,'before'=>$before,'after'=>$after];
            }
            $pairs[]=['decision_id'=>$current->id,'product'=>$a['evidence']['product']??null,'current_at'=>$a['generated_at'],'previous_at'=>$b['generated_at']??null,'changes'=>$delta,'state'=>$b?'compared':'no_previous_snapshot','url'=>$a['url']];
        }
        return ['rows'=>$pairs,'qualification'=>'Recorded immutable decision versions only; no reconstructed yesterday balances.','as_of'=>now()->toIso8601String()];
    }
}
