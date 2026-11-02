<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Automation;
use App\Models\BusinessEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\OperationalTask;
use App\Models\Product;
use App\Models\PurchaseRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Source review and explicit draft preparation on existing tasks; no second workflow engine. */
class AutomationTaskWorkflow
{
    private function visible(OperationalTask $task): array
    {
        app(AutomationService::class)->authorize('tasks.view');
        $row = collect(app(ActionCenterService::class)->tasks(['task' => $task->id, 'status' => 'all'])->items())->first();
        abort_unless($row, 404);

        return $row;
    }

    public function claim(OperationalTask $task): array
    {
        app(AutomationService::class)->authorize('tasks.claim');

        return DB::transaction(function () use ($task) {
            Company::whereKey(Auth::user()->company_id)->sharedLock()->firstOrFail();
            $task = OperationalTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $view = $this->visible($task);
            abort_unless($view['can_claim'] || $task->assigned_user_id === Auth::id(), 409, 'This task is assigned or outside your queue.');
            abort_unless(in_array($task->status, ['open', 'in_progress'], true), 409, 'This task is no longer active.');
            if (! $task->assigned_user_id) {
                $task->update(['assigned_user_id' => Auth::id(), 'status' => 'in_progress']);
                app(AutomationService::class)->audit('task.claimed', $task, ['assigned_user_id' => Auth::id(), 'team' => $task->assigned_team]);
            }

            return app(ActionCenterService::class)->present($task);
        });
    }

    public function review(OperationalTask $task): array
    {
        $view = $this->visible($task);
        $outcome = $task->outcome ?? [];
        $rule = $task->automation_id ? Automation::find($task->automation_id) : null;
        $event = $rule ? new BusinessEvent(['event_type' => $rule->trigger, 'entity_type' => $task->source_type, 'entity_id' => $task->source_id, 'reference' => $task->title, 'metadata' => []]) : null;
        $current = $event ? app(AutomationRegistry::class)->currentContext($event) : [];
        $recommendation = null;
        if ($task->source_type === 'Product' && app(AutomationOperations::class)->can('analytics.view') && app(AutomationOperations::class)->can('inventory.view')) {
            try {
                $plan = app(InventoryPlanningService::class)->decisionEvidence((int) $task->source_id)['plan'];
                $recommendation = collect($plan)->only(['fingerprint', 'supplier_id', 'supplier_name', 'base_quantity', 'quantity', 'unit', 'unit_price', 'currency', 'feasible', 'coverage_supported', 'quality', 'risk', 'optimization_state', 'expected_arrival', 'assumptions'])->all();
                if (! app(AutomationOperations::class)->can('analytics.finance')) {
                    $recommendation['unit_price'] = null;
                }
            } catch (ValidationException $e) {
                $recommendation = ['available' => false, 'reason' => 'Review planning configuration and source evidence.'];
            }
        }
        $history = ActivityLog::with('user:id,name')->where('entity', 'OperationalTask')->where('entity_id', $task->id)->latest('id')->limit(30)->get(['id', 'action', 'user_id', 'created_at'])->map(fn ($row) => ['id' => $row->id, 'action' => str($row->action)->replace(['.', '_'], ' ')->headline()->toString(), 'actor' => $row->user?->name ?? 'System', 'at' => $row->created_at?->toIso8601String()]);
        if (! app(AutomationOperations::class)->can('analytics.finance')) {
            unset($outcome['workflow']['prepared']['unit_price']);
        }
        $draft = isset($outcome['draft']['id']) ? PurchaseRequest::find($outcome['draft']['id']) : null;

        return ['task' => $view, 'rule' => $rule?->only(['id', 'name', 'version', 'trigger', 'enabled', 'lifecycle_state']),
            'trigger_evidence' => $outcome['automation']['trigger_evidence'] ?? null, 'current_evidence' => $current, 'recommendation' => $recommendation,
            'workflow' => $outcome['workflow'] ?? ['step' => in_array($task->status, ['completed', 'cancelled'], true) ? $task->status : 'review'],
            'draft' => $draft?->only(['id', 'request_number', 'status', 'approval_request_id']),
            'can_prepare' => $view['can_complete'] && (! $rule || ($rule->enabled && ! app(AutomationOperations::class)->paused($rule))) && $task->source_type === 'Product' && app(AutomationOperations::class)->can('procurement.manage') && app(AutomationOperations::class)->can('analytics.finance') && ($recommendation['feasible'] ?? false) && ($recommendation['coverage_supported'] ?? false),
            'history' => $history, 'priority_reason' => $outcome['escalation']['reason'] ?? ($view['overdue'] ? 'Task due date has passed.' : 'Explicit rule or owner priority: '.$task->priority),
            'qualification' => 'Trigger evidence explains the original observation. Current evidence is rechecked before draft creation. A draft creates no PO, payment, journal, payroll or fiscal submission.'];
    }

    public function prepare(OperationalTask $task): array
    {
        return DB::transaction(function () use ($task) {
            Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
            $task = OperationalTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $this->mayPrepare($task);
            if (isset($task->outcome['draft'])) {
                return ['draft' => $task->outcome['draft'], 'reused' => true];
            }
            $product = Product::whereKey($task->source_id)->lockForUpdate()->firstOrFail();
            $plan = app(InventoryPlanningService::class)->decisionEvidence($product->id)['plan'];
            abort_unless(($plan['coverage_supported'] ?? false) && $plan['feasible'] && $plan['quantity'] > 0 && $plan['supplier_id'] && $plan['unit_price'] !== null, 422, 'Qualified demand, supplier, price and feasible positive quantity are required. Review V11 evidence.');
            $review = collect($plan)->only(['fingerprint', 'supplier_id', 'supplier_name', 'base_quantity', 'quantity', 'unit', 'unit_price', 'currency', 'expected_arrival', 'quality', 'risk'])->all();
            $review['prepared_at'] = now()->toIso8601String();
            $review['prepared_by'] = Auth::id();
            $review['confirmation_id'] = (string) Str::uuid();
            $review['rule_version'] = $task->automation_id ? Automation::findOrFail($task->automation_id)->version : null;
            $outcome = $task->outcome ?? [];
            $outcome['workflow'] = ['step' => 'awaiting_confirmation', 'prepared' => $review];
            $task->update(['outcome' => $outcome]);
            app(AutomationService::class)->audit('task.draft_prepared', $task, $review);

            return ['review' => $review, 'confirmation_required' => true, 'draft_only' => true];
        });
    }

    private function mayPrepare(OperationalTask $task): void
    {
        $view = $this->visible($task);
        abort_unless($view['can_complete'] && $task->source_type === 'Product', 403);
        app(InventoryPlanningService::class)->authorize(true);
        app(AutomationService::class)->authorize('analytics.finance');
        if ($task->automation_id) {
            $rule = Automation::findOrFail($task->automation_id);
            abort_if(! $rule->enabled || $rule->lifecycle_state === 'archived' || app(AutomationOperations::class)->paused($rule), 409, 'Automation is paused. Manual procurement remains available.');
        }
        abort_unless(in_array($task->status, ['open', 'in_progress'], true) || isset($task->outcome['draft']), 409, 'The source issue is no longer active.');
    }

    public function confirmDraft(OperationalTask $task, array $input): array
    {
        $data = validator($input, ['confirmation_id' => 'required|uuid', 'confirm' => 'required|accepted'])->validate();

        return DB::transaction(function () use ($task, $data) {
            Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
            $task = OperationalTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $this->mayPrepare($task);
            $prepared = $task->outcome['workflow']['prepared'] ?? null;
            abort_unless($prepared && hash_equals($prepared['confirmation_id'], $data['confirmation_id']), 409, 'Prepare and review this action before confirming.');
            if (isset($task->outcome['draft'])) {
                return ['draft' => $task->outcome['draft'], 'reused' => true];
            }
            Product::whereKey($task->source_id)->lockForUpdate()->firstOrFail();
            $plan = app(InventoryPlanningService::class)->decisionEvidence($task->source_id)['plan'];
            if (($task->automation_id && ($prepared['rule_version'] ?? null) !== Automation::findOrFail($task->automation_id)->version) || ! hash_equals($prepared['fingerprint'], $plan['fingerprint']) || now()->diffInMinutes(Carbon::parse($prepared['prepared_at']), true) > 15) {
                $outcome = $task->outcome;
                $outcome['workflow']['step'] = 'needs_review';
                $outcome['workflow']['blocked_reason'] = 'Source evidence changed or the review expired. Recompute and review before creating a draft.';
                $task->update(['outcome' => $outcome]);
                app(AutomationService::class)->audit('task.prepared_action_stale', $task, ['current_fingerprint' => $plan['fingerprint']]);

                return ['stale' => true, 'message' => $outcome['workflow']['blocked_reason']];
            }
            $saved = app(InventoryPlanningService::class)->save($task->source_id);
            $selection = ['recommendations' => [$saved['recommendation_id']]];
            $review = app(InventoryPlanningService::class)->consolidate($selection);
            $result = app(InventoryPlanningService::class)->draft($selection + ['review_fingerprint' => $review['review_fingerprint']]);
            $request = $result['purchase_requests'][0];
            $draft = ['type' => 'purchase_request', 'id' => $request->id, 'reference' => $request->request_number, 'url' => '/procurement?request='.$request->id];
            $outcome = $task->outcome;
            $outcome['draft'] = $draft;
            $outcome['workflow']['step'] = 'awaiting_procurement_submission';
            $outcome['workflow']['confirmed_by'] = Auth::id();
            $outcome['workflow']['confirmed_at'] = now()->toIso8601String();
            $task->update(['outcome' => $outcome]);
            app(AutomationService::class)->audit('task.purchase_request_drafted', $task, $draft);

            return ['draft' => $draft, 'draft_only' => true, 'reused' => false];
        });
    }

    public function promise(OperationalTask $task, array $input): array
    {
        $data = validator($input, ['due_on' => 'required|date_format:Y-m-d|after_or_equal:today', 'amount' => 'required|numeric|gt:0', 'note' => 'required|string|min:5|max:1000'])->validate();

        return DB::transaction(function () use ($task, $data) {
            Company::whereKey(Auth::user()->company_id)->sharedLock()->firstOrFail();
            $task = OperationalTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->visible($task)['can_complete'] && $task->source_type === 'Customer', 403);
            abort_unless(in_array($task->status, ['open', 'in_progress'], true), 409);
            $customer = Customer::findOrFail($task->source_id);
            $promise = $data + ['recorded_by' => Auth::id(), 'recorded_at' => now()->toIso8601String(), 'ledger_cursor' => $customer->debtTransactions()->max('id') ?? 0, 'overdue_at_recording' => app(CustomerCreditService::class)->exposure($customer)['overdue']];
            $outcome = $task->outcome ?? [];
            $outcome['collection']['promise'] = $promise;
            unset($outcome['collection']['promise_progress']);
            $task->update(['status' => 'in_progress', 'due_at' => $data['due_on'].' 23:59:59', 'escalated_at' => null, 'outcome' => $outcome]);
            app(AutomationService::class)->audit('task.payment_promise_recorded',$task,$promise);

            return app(ActionCenterService::class)->present($task);
        });
    }
}
