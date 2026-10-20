<?php

namespace Tests\Feature;

use App\Models\{ApprovalDecision, ApprovalRequest, ApprovalRule, PurchaseRequest};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApprovalAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_write_failure_rolls_back_decision_and_retry_is_exactly_once(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        ApprovalRule::create($this->tenantAttributes(['rule_type'=>'purchase_request', 'threshold_amount'=>0, 'required_role'=>'admin', 'separation_of_duties'=>false, 'is_active'=>true]));
        $parent = PurchaseRequest::create($this->tenantAttributes(['request_number'=>'DC1-ATOMIC', 'status'=>'submitted', 'requested_by'=>auth()->id(), 'requested_at'=>today(), 'estimated_total'=>10, 'currency'=>'EUR']));
        $approval = ApprovalRequest::create($this->tenantAttributes(['entity_type'=>'purchase_request', 'entity_id'=>$parent->id, 'rule_type'=>'purchase_request', 'requested_by'=>auth()->id(), 'requested_at'=>now(), 'required_role'=>'admin', 'requested_amount'=>10, 'currency'=>'EUR', 'status'=>'pending']));
        $parent->update(['approval_request_id'=>$approval->id]);
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && preg_match('/^update ["`]purchase_requests["`]/i', $query->sql)) {
                $fail = false;
                throw new \RuntimeException('Injected parent write interruption.');
            }
        });
        $path='/api/approvals/'.$approval->id.'/decision';
        $payload=['decision'=>'approved', 'comment'=>'Reviewed source'];
        $this->postJson($path,$payload)->assertStatus(500);
        $this->assertSame('pending',$approval->fresh()->status);
        $this->assertSame('submitted',$parent->fresh()->status);
        $this->assertSame(0,ApprovalDecision::count());
        $this->postJson($path,$payload)->assertOk();
        $this->assertSame('approved',$parent->fresh()->status);
        $this->postJson($path,$payload)->assertOk();
        $this->assertSame(1,ApprovalDecision::count());
        $this->postJson($path,['decision'=>'rejected','comment'=>'Changed decision'])->assertUnprocessable();
        $this->assertSame('approved',$parent->fresh()->status);
    }
}
