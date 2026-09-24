<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    public const CUSTOMER_CREDIT_OVERRIDE = 'customer_credit_override';

    public function isRequired(string $ruleType, string $amount): bool
    {
        $rule = ApprovalRule::query()->where('rule_type', $ruleType)->where('is_active', true)->first();

        return $rule && ($rule->threshold_amount === null || Money::compare($amount, $rule->threshold_amount) >= 0);
    }

    public function requestIfRequired(string $entityType, int $entityId, string $ruleType, string $amount, string $currency, array $context = []): ?ApprovalRequest
    {
        $user = Auth::user();
        $rule = ApprovalRule::query()->where('rule_type', $ruleType)->where('is_active', true)->first();
        if (! $rule || ($rule->threshold_amount !== null && Money::compare($amount, $rule->threshold_amount) < 0)) {
            return null;
        }

        $existing = ApprovalRequest::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('rule_type', $ruleType)
            ->whereIn('status', ['pending', 'approved', 'rejected'])
            ->latest('id')
            ->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $rule, $entityType, $entityId, $ruleType, $amount, $currency, $context) {
            $request = ApprovalRequest::create([
                'company_id' => $user->company_id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'rule_type' => $ruleType,
                'requested_by' => $user->id,
                'requested_at' => now(),
                'required_user_id' => $rule->required_user_id,
                'required_role' => $rule->required_role,
                'status' => 'pending',
                'requested_amount' => Money::normalize($amount),
                'currency' => $currency,
                'context' => $context,
            ]);
            $this->audit($request, 'approval.requested', 'Approval requested.');

            return $request;
        });
    }

    /**
     * Create a credit override in the shared approval ledger. The caller locks
     * the customer first, which serializes duplicate requests for one intent.
     */
    public function requestCustomerCreditOverride(Customer $customer, string $amount, string $currency, array $context): ApprovalRequest
    {
        $user = Auth::user();
        $signature = (string) ($context['intent_signature'] ?? '');
        if ($signature === '') {
            throw ValidationException::withMessages([
                'credit_override' => ['The protected transaction signature is required.'],
            ]);
        }

        $existing = ApprovalRequest::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('entity_type', self::CUSTOMER_CREDIT_OVERRIDE)
            ->where('entity_id', $customer->id)
            ->where('rule_type', self::CUSTOMER_CREDIT_OVERRIDE)
            ->whereIn('status', ['pending', 'approved'])
            ->lockForUpdate()
            ->get()
            ->first(fn (ApprovalRequest $request): bool => hash_equals(
                (string) data_get($request->context, 'intent_signature', ''),
                $signature,
            ));
        if ($existing) {
            return $existing;
        }

        $rule = ApprovalRule::query()
            ->where('rule_type', self::CUSTOMER_CREDIT_OVERRIDE)
            ->first();
        if ($rule && ! $rule->is_active) {
            throw ValidationException::withMessages([
                'credit_override' => ['Customer credit override approvals are disabled.'],
            ]);
        }

        $request = ApprovalRequest::create([
            'company_id' => $user->company_id,
            'entity_type' => self::CUSTOMER_CREDIT_OVERRIDE,
            'entity_id' => $customer->id,
            'rule_type' => self::CUSTOMER_CREDIT_OVERRIDE,
            'requested_by' => $user->id,
            'requested_at' => now(),
            'required_user_id' => $rule?->required_user_id,
            // With no configured rule, any user who already holds the shared
            // approval-decision permission may act (subject to self-approval).
            'required_role' => $rule?->required_role,
            'status' => 'pending',
            'requested_amount' => Money::normalize($amount),
            'currency' => $currency,
            'context' => $context,
        ]);
        $this->audit($request, 'approval.requested', 'Customer credit override requested.');

        return $request;
    }

    public function lockCustomerCreditOverride(int $approvalId, Customer $customer): ?ApprovalRequest
    {
        return ApprovalRequest::withoutGlobalScopes()
            ->where('company_id', Auth::user()->company_id)
            ->where('entity_type', self::CUSTOMER_CREDIT_OVERRIDE)
            ->where('entity_id', $customer->id)
            ->where('rule_type', self::CUSTOMER_CREDIT_OVERRIDE)
            ->lockForUpdate()
            ->find($approvalId);
    }

    public function consumeCustomerCreditOverride(ApprovalRequest $request, CustomerDebtTransaction $transaction): void
    {
        if ($request->status !== 'approved') {
            throw ValidationException::withMessages([
                'approval_request_id' => ['Only an approved, unused credit override can be consumed.'],
            ]);
        }

        $request->update(['status' => 'consumed']);
        ApprovalDecision::create([
            'approval_request_id' => $request->id,
            'company_id' => $request->company_id,
            'actor_id' => Auth::id(),
            'decision' => 'consumed',
            'comment' => 'Applied to the approved customer debt transaction.',
            'decided_at' => now(),
            'snapshot' => [
                ...$request->only(['entity_type', 'entity_id', 'rule_type', 'requested_amount', 'currency', 'context']),
                'customer_debt_transaction_id' => $transaction->id,
            ],
        ]);
        $this->audit($request, 'approval.consumed', 'Customer credit override consumed.');
    }

    public function approved(string $entityType, int $entityId, string $ruleType): bool
    {
        $request = ApprovalRequest::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('rule_type', $ruleType)
            ->latest('id')
            ->first();

        return ! $this->requires($ruleType) || $request?->status === 'approved';
    }

    public function requires(string $ruleType): bool
    {
        return ApprovalRule::query()->where('rule_type', $ruleType)->where('is_active', true)->exists();
    }

    public function invalidate(string $entityType, int $entityId, string $ruleType, string $reason): void
    {
        $request = ApprovalRequest::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('rule_type', $ruleType)
            ->whereIn('status', ['pending', 'approved', 'rejected'])
            ->latest('id')
            ->first();
        if (! $request) {
            return;
        }

        DB::transaction(function () use ($request, $reason) {
            $request->update([
                'status' => 'cancelled',
                'decided_by' => Auth::id(),
                'decided_at' => now(),
                'decision_comment' => $reason,
            ]);
            ApprovalDecision::create([
                'approval_request_id' => $request->id,
                'company_id' => $request->company_id,
                'actor_id' => Auth::id(),
                'decision' => 'cancelled',
                'comment' => $reason,
                'decided_at' => now(),
                'snapshot' => $request->only(['entity_type', 'entity_id', 'rule_type', 'requested_amount', 'currency', 'context']),
            ]);
            $this->audit($request, 'approval.cancelled', $reason);
        });
    }

    public function pendingForUser(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return ApprovalRequest::query()
            ->with('decisions')
            ->where('status', 'pending')
            ->where(function ($query) use ($user) {
                $query->where('required_user_id', $user->id)
                    ->orWhere(function ($role) use ($user) {
                        $role->whereNull('required_user_id')
                            ->where(fn ($routing) => $routing
                                ->whereNull('required_role')
                                ->orWhere('required_role', $user->role));
                    });
            })
            ->latest('requested_at')
            ->get()->filter(function($request){
                if($request->entity_type!=='document_version')return true;
                $documents=app(DocumentService::class);if(!$documents->can('documents.review')||!$documents->can('documents.view'))return false;
                return $documents->visibleQuery()->whereHas('versions',fn($v)=>$v->where('id',$request->entity_id))->exists();
            })->values();
    }

    public function decide(ApprovalRequest $request, string $decision, ?string $comment): ApprovalRequest
    {
        if ($request->entity_type === 'document_version') {
            $documents=app(DocumentService::class);
            $documents->permit('documents.review');
            $documents->permit('approvals.decide');
            $version=\App\Models\DocumentVersion::query()->findOrFail($request->entity_id);
            $document=$documents->document($version->document_id);
            abort_unless($document->current_version === $version->version && $document->status !== 'archived',422,'Only the current, unarchived document version can be reviewed.');
        }
        return DB::transaction(function () use ($request, $decision, $comment) {
            $user = Auth::user();
            $request = ApprovalRequest::withoutGlobalScopes()
                ->where('company_id', $user->company_id)
                ->lockForUpdate()
                ->findOrFail($request->id);

            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['approval' => ['This approval was already decided and cannot be changed.']]);
            }
            if (($request->required_user_id && (int) $request->required_user_id !== (int) $user->id)
                || (! $request->required_user_id && $request->required_role && $request->required_role !== $user->role)) {
                throw ValidationException::withMessages(['approval' => ['You are not the assigned approver.']]);
            }

            $rule = ApprovalRule::query()->where('rule_type', $request->rule_type)->first();
            if (($rule?->separation_of_duties ?? true) && (int) $request->requested_by === (int) $user->id) {
                throw ValidationException::withMessages(['approval' => ['The requester cannot approve their own request.']]);
            }
            if (! in_array($decision, ['approved', 'rejected', 'cancelled'], true)) {
                throw ValidationException::withMessages(['decision' => ['Invalid approval decision.']]);
            }

            $request->update([
                'status' => $decision,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
            ]);
            ApprovalDecision::create([
                'approval_request_id' => $request->id,
                'company_id' => $request->company_id,
                'actor_id' => $user->id,
                'decision' => $decision,
                'comment' => $comment,
                'decided_at' => now(),
                'snapshot' => $request->only(['entity_type', 'entity_id', 'rule_type', 'requested_amount', 'currency', 'context']),
            ]);
            $this->audit($request, 'approval.'.$decision, 'Approval '.$decision.'.');

            if ($request->entity_type === 'document_version') {
                $v=\App\Models\DocumentVersion::findOrFail($request->entity_id);
                $d=\App\Models\Document::whereKey($v->document_id)->lockForUpdate()->firstOrFail();
                abort_unless((int)$d->current_version===(int)$v->version && $d->status!=='archived',422,'The document version changed before this decision.');
                $d->update(['status'=>$decision==='cancelled'?'draft':$decision]);
                app(DocumentService::class)->event($d,$decision,['version'=>$v->version,'approver'=>$user->id,'comment'=>$comment]);
            }

            return $request->fresh('decisions');
        });
    }

    private function audit(ApprovalRequest $request, string $action, string $description): void
    {
        ActivityLog::create([
            'company_id' => $request->company_id,
            'user_id' => Auth::id(),
            'action' => $action,
            'entity' => 'ApprovalRequest',
            'entity_id' => $request->id,
            'description' => $description,
            'new_value' => $request->only(['entity_type', 'entity_id', 'rule_type', 'status', 'requested_amount', 'currency']),
        ]);
    }
}
