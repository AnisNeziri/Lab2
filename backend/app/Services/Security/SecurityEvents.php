<?php
namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SecurityEvents
{
    public function record(string $event, ?User $user = null, array $context = [], ?int $subject = null): void
    {
        $user ??= auth()->user();
        // Never accept arbitrary request bodies, tokens, email addresses or secret material.
        $safe=array_intersect_key($context,array_flip(['method','count','role','permission_groups','policy','status','license_id','manifest_hash','reason_code','capabilities']));
        DB::table('security_events')->insert(['company_id'=>$user?->company_id,'user_id'=>$user?->id,
            'event'=>$event,'subject_id'=>$subject,'request_id'=>request()->attributes->get('request_id'),
            'context'=>json_encode($safe),'created_at'=>now()]);
    }
}
