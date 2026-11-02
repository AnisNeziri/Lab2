<?php

namespace App\Services;

use App\Models\Automation;
use App\Models\Company;
use App\Models\OperationalTask;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Company controls and bounded operational reads for the existing rule engine. */
class AutomationOperations
{
    public const TEAMS = ['sales' => 'debts.view', 'purchasing' => 'procurement.view', 'warehouse' => 'inventory.view', 'finance' => 'finance.view', 'hr' => 'employees.view', 'management' => 'tasks.manage'];

    public function can(string $permission): bool
    {
        return app(PermissionService::class)->roleHasPermission(Auth::user()->role, $permission);
    }

    public function category(string $trigger): string
    {
        return match (explode('.', $trigger)[0]) {
            'customer','quotation','sales_order','order','packing','dispatch','delivery','customer_return' => 'sales',
            'purchase_order','supplier','supplier_delivery','supplier_model' => 'purchasing',
            'shipment' => 'shipments','accounting','finance' => 'finance','fiscalization','tax' => 'tax',
            'payroll','employee','workforce' => 'workforce','document' => 'documents','integration','task','operating' => 'operations',
            default => 'inventory',
        };
    }

    public function controls(?Company $company = null): array
    {
        $company ??= Company::findOrFail(Auth::user()->company_id);

        return array_replace(['paused' => false, 'paused_categories' => [], 'reason' => null, 'changed_by' => null, 'changed_at' => null], $company->automation_settings ?? []);
    }

    public function paused(Automation $rule, ?Company $company = null): bool
    {
        $entitlement=app(\App\Services\Commercial\Entitlements::class)->state($rule->company_id);
        if(!in_array('automation',$entitlement['capabilities'],true) || !$entitlement['write_allowed']) return true;
        $policy = $this->controls($company);

        return $policy['paused'] || in_array($this->category($rule->trigger), $policy['paused_categories'], true);
    }

    public function pause(array $input): array
    {
        app(AutomationService::class)->authorize('automations.pause');
        $data = validator($input, ['paused' => 'required|boolean', 'paused_categories' => 'present|array|max:11',
            'paused_categories.*' => 'in:inventory,sales,purchasing,shipments,finance,tax,workforce,documents,operations',
            'reason' => 'required|string|min:5|max:1000'])->validate();

        return DB::transaction(function () use ($data) {
            $company = Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
            $settings = $data + ['changed_by' => Auth::id(), 'changed_at' => now()->toIso8601String()];
            $company->update(['automation_settings' => $settings]);
            app(AutomationService::class)->audit('automation.company_control_changed', $company, $settings);

            return $settings;
        });
    }

    public function health(): array
    {
        app(AutomationService::class)->authorize('automations.health.view');
        $rules = app(AutomationService::class)->visibleRules();
        $runs = app(AutomationService::class)->visibleExecutions();
        $statuses = (clone $runs)->where('created_at', '>=', now()->subDays(30))->selectRaw('status,COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $tasks = OperationalTask::whereIn('automation_id', (clone $rules)->select('id'));
        $duration = DB::connection()->getDriverName() === 'sqlite' ? '(julianday(completed_at)-julianday(created_at))*24' : 'TIMESTAMPDIFF(SECOND,created_at,completed_at)/3600';
        $closed = (clone $tasks)->whereNotNull('completed_at')->where('created_at', '>=', now()->subDays(30))->selectRaw('COUNT(*) as count,AVG('.$duration.') as mean_hours')->first();
        $latest = (clone $runs)->whereIn('status', ['failed', 'blocked', 'needs_verification'])->latest('id')->limit(15)->get(['id', 'automation_id', 'version', 'status', 'error', 'failure_class', 'attempts', 'next_retry_at', 'created_at']);
        $queue = ['scope' => 'company execution ledger', 'pending' => (clone $runs)->where('status', 'queued')->count(), 'processing' => (clone $runs)->where('status', 'running')->count(), 'oldest_created_at' => (clone $runs)->where('status', 'queued')->min('created_at'), 'failed' => (clone $runs)->where('status', 'failed')->count(), 'worker_job_counts_available' => false];
        $controls = $this->controls();
        $heartbeat = Cache::get('automation-heartbeat:'.Auth::user()->company_id);

        return ['controls' => $controls, 'state' => $controls['paused'] ? 'paused' : ($latest->contains(fn ($run) => in_array($run->status, ['failed', 'needs_verification'], true)) ? 'attention' : ($heartbeat ? 'observed' : 'not_observed')),
            'heartbeat_at' => $heartbeat, 'active_rules' => (clone $rules)->where('enabled', true)->count(),
            'paused_rules' => (clone $rules)->where('lifecycle_state', 'paused')->count(), 'draft_rules' => (clone $rules)->where('lifecycle_state', 'draft')->count(),
            'archived_rules' => (clone $rules)->where('lifecycle_state', 'archived')->count(), 'statuses' => $statuses,
            'retrying' => (clone $runs)->where('failure_class', 'retryable')->whereNotNull('next_retry_at')->count(),
            'unknown_outcomes' => (clone $runs)->where('status', 'needs_verification')->count(),
            'waiting_approvals' => $this->can('approvals.view') ? app(ApprovalService::class)->pendingForUser(Auth::user())->count() : null,
            'tasks_created_30_days' => (clone $tasks)->where('created_at', '>=', now()->subDays(30))->count(),
            'tasks_resolved_30_days' => (int) $closed->count, 'mean_resolution_hours' => $closed->mean_hours === null ? null : round((float) $closed->mean_hours, 2),
            'prepared_actions' => (clone $tasks)->whereNotNull('outcome->workflow->prepared')->count(), 'recent_failures' => $latest, 'queue' => $queue,
            'manual_operations_available' => true, 'qualification' => 'Automation pause affects orchestration only. Pending/processing counts describe the company execution ledger; raw worker job counts are unavailable without tenant-aware queue telemetry. No other tenant records or job payloads are exposed. Missing heartbeat is unknown health, not proof of failure.'];
    }

    public function brief(?string $team = null, bool $overdue = false, bool $management = false, int $days = 7): array
    {
        return app(PermissionService::class)->readScope(fn()=> $this->readBrief($team,$overdue,$management,$days));
    }

    private function readBrief(?string $team, bool $overdue, bool $management, int $days): array
    {
        app(AutomationService::class)->authorize('tasks.view');
        if ($team) {
            abort_unless(isset(self::TEAMS[$team]) && $this->can(self::TEAMS[$team]), 403);
        }
        if ($management) {
            abort_unless($days >= 1 && $days <= 31, 422);
            return app(OperatingBriefSummary::class)->read($team, $days);
        }
        $snapshot = app(ActionCenterService::class)->snapshot(['view' => ($team || $this->can('tasks.manage')) ? 'all' : 'mine', 'team' => $team, 'overdue' => $overdue]);
        $tasks = collect($snapshot['tasks']->items())->take(12)->all();
        $areas = match ($team) {
            'sales' => ['customer', 'order'],'purchasing' => ['procurement', 'shipment', 'stock'],'warehouse' => ['stock', 'order'],'finance' => ['finance', 'customer'],'hr' => ['payroll', 'workforce'],default => null
        };
        $issues = $overdue ? [] : collect($snapshot['attention'])->filter(fn ($i) => ! $areas || in_array($i['kind'], $areas, true))->sortBy(fn ($i) => match ($i['severity'] ?? 'attention') {
            'critical' => 0,'urgent' => 1,default => 2
        })->take(12)->values()->all();

        return ['as_of' => now()->toIso8601String(), 'tasks' => $tasks, 'issues' => $issues, 'controls' => $this->controls(),
            'priority_basis' => 'Overdue due dates first, then explicit urgency/severity. No AI score.',
            'qualification' => 'Bounded current operational inbox. Source permissions apply; open source modules for full work lists.'];
    }
}
