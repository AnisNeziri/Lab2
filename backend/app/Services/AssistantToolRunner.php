<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Per-question limits and strict arguments precede the existing authorization boundary. */
final class AssistantToolRunner
{
    private array $seen=[];
    private float $started;
    public array $executions=[];
    public function __construct(private readonly AimsToolRegistry $registry) {$this->started=microtime(true);}
    public function allowed(string $name):bool {return collect($this->registry->catalog())->contains('name',$name);}
    public function execute(string $name,array $input=[]):array
    {
        $def=collect($this->registry->catalog())->firstWhere('name',$name);
        abort_unless($def&&$def['read_only'],403,'This tool is unavailable with your permissions.');
        $key=hash('sha256',$name.json_encode($input));
        abort_if(isset($this->seen[$key])||count($this->seen)>=config('assistant.max_tools')||microtime(true)-$this->started>config('assistant.runtime_seconds'),422,'Assistant tool budget reached; ask a narrower question.');
        $rules=[];
        foreach($def['input_schema']['properties']??[] as $field=>$schema) {
            $rules[$field]=[in_array($field,$def['input_schema']['required']??[])?'required':'sometimes',match($schema['type']??'string'){'integer'=>'integer','number'=>'numeric','array'=>'array',default=>'string'}];
            if(isset($schema['minimum']))$rules[$field][]='min:'.$schema['minimum'];
            if(isset($schema['enum']))$rules[$field][]='in:'.implode(',',$schema['enum']);
        }
        if(in_array($name,['simulate_inventory_scenario','simulate_decision_change'])) {
            $rules['scenarios']=['required','array','min:1','max:4'];
            $rules['scenarios.*']=['array:warehouse_id,supplier_id,unit,base_quantity,budget,delay_days,demand_multiplier,service_level,order_date,type,source_warehouse_id,transfer_days'];
        }
        foreach(array_keys($input) as $field)if(!isset($rules[$field]))throw ValidationException::withMessages(['input'=>['Unsupported tool argument: '.$field]]);
        $validated=validator($input,$rules)->validate();$this->seen[$key]=true;
        try{$result=$this->registry->execute($name,$validated);$this->executions[]=['tool'=>$name,'arguments'=>$validated,'status'=>'success'];return $result['data'];}
        catch(\Throwable $e){$this->executions[]=['tool'=>$name,'arguments'=>$validated,'status'=>'failed'];throw $e;}
    }
}
