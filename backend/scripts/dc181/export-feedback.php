<?php
require __DIR__.'/bootstrap.php';
$token=dc17Login('primary');dc17Request('GET','intelligence-assistant/status',[],$token);app(App\Services\PilotService::class)->owner();
$rows=App\Models\ActivityLog::where('action','assistant.feedback')->latest('id')->limit(100)->get()->map(fn($r)=>['feedback_id'=>$r->id,'recorded_at'=>$r->created_at->toIso8601String(),'review_status'=>'pending','feedback'=>array_intersect_key($r->new_value??[],array_flip(['response_id','helpful','reason','provider','model','model_revision','tools','vocabulary_version']))]);
dc17Evidence('feedback-review',['company_id'=>auth()->user()->company_id,'automatic_training'=>false,'items'=>$rows->all(),'policy'=>'Administrator reviews privacy and correctness, then manually approves a minimized example for a separate held-out evaluation file. No aliases, datasets or models are modified by this export.']);echo 'Minimized feedback review exported; no training or automatic updates. '.count($rows).' rows.';
