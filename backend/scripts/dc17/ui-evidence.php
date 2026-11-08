<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\{Auth,DB};
$account=json_decode(file_get_contents($dc16Root.'/accounts.json'),true)['primary'];Auth::setUser(App\Models\User::withoutGlobalScopes()->findOrFail($account['user_id']));
$task=App\Models\OperationalTask::where('title','DC17 keyboard and tablet acceptance')->sole();
$history=App\Models\ActivityLog::where('entity','OperationalTask')->where('entity_id',$task->id)->orderBy('id')->get(['action','user_id','created_at']);
$checks=['one_task'=>App\Models\OperationalTask::where('title',$task->title)->count()===1,'completed'=>$task->status==='completed','claimed_by_owner'=>$task->assigned_user_id===Auth::id(),'completion_note'=>str_contains($task->outcome['note']??'','claimed and completed'),'claim_audited'=>$history->contains('action','task.claimed'),'completion_audited'=>$history->contains('action','task.completed')];
$lastOrder=json_decode(file_get_contents($dc17Root.'/evidence/prospective-dispatch.json'),true)['order_id'];
$checks['sales_lookup_created_no_order']=(int)DB::table('sales_orders')->max('id')===(int)$lastOrder;
dc17Evidence('browser-workflow',['company'=>2,'task_id'=>$task->id,'checks'=>$checks,'audit'=>$history,'viewports'=>['normal desktop','820x1180 tablet'],'modes'=>['English/light order','Albanian/dark order and task'],'keyboard'=>'Enter used for save, ownership change, claim and completion','screenshots'=>['order-shortage-en.png','order-shortage-sq-tablet.png','task-completed-tablet.png'],'scope'=>'Affected order/task screens plus preserved DC16 mobile warehouse review; no entire-ERP/device certification']);
echo 'Browser task persisted/audited: '.(!in_array(false,$checks,true)?'PASS':'FAIL')."\n";if(in_array(false,$checks,true))exit(2);
