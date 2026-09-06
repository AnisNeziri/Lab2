<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\PurchaseRequest;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class ApprovalController extends Controller { public function __construct(private readonly ApprovalService $approvals){} public function pending():JsonResponse{return response()->json($this->approvals->pendingForUser(auth()->user()));} public function rules():JsonResponse{return response()->json(ApprovalRule::query()->orderBy('rule_type')->get());} public function saveRule(Request $request):JsonResponse{$data=$request->validate(['rule_type'=>['required',Rule::in(['purchase_request','purchase_order','customer_credit_override'])],'threshold_amount'=>['nullable','numeric','min:0'],'currency'=>['nullable','string','size:3'],'required_role'=>['nullable',Rule::in(['admin','manager','staff'])],'required_user_id'=>['nullable','integer'],'separation_of_duties'=>['boolean'],'is_active'=>['boolean']]);$rule=ApprovalRule::updateOrCreate(['company_id'=>auth()->user()->company_id,'rule_type'=>$data['rule_type']],$data);return response()->json($rule);} public function decide(Request $request,ApprovalRequest $approvalRequest):JsonResponse{$data=$request->validate(['decision'=>['required',Rule::in(['approved','rejected','cancelled'])],'comment'=>['nullable','string','max:2000']]);$result=$this->approvals->decide($approvalRequest,$data['decision'],$data['comment']??null);if($result->entity_type==='purchase_request'){PurchaseRequest::withoutGlobalScopes()->where('company_id',$result->company_id)->where('id',$result->entity_id)->update(['status'=>$result->status==='approved'?'approved':'rejected']);}return response()->json($result);} }
