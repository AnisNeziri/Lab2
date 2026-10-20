<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\BackupRun;
use App\Models\InventoryCountSession;
use App\Models\PilotEvidence;
use App\Models\PilotFeedback;
use App\Models\PilotProfile;
use App\Models\User;
use App\Support\PilotPrivacy;
use App\Support\Release;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PilotService
{
    public const STREAMS = ['inventory', 'sales', 'purchasing', 'receiving', 'finance', 'intelligence'];

    public const CATEGORIES = ['confusing', 'missing_action', 'too_many_clicks', 'slow', 'terminology', 'missing_data', 'error', 'feature_request'];

    public const KINDS = ['source_review', 'opening_inventory', 'opening_finance', 'daily_reconciliation', 'weekly_review', 'physical_count', 'goods_receipt', 'order_lifecycle', 'customer_payment', 'supplier_payment', 'supplier_invoice', 'shipment', 'assistant_review', 'automation_review', 'permission_review', 'account_offboarding', 'role_change', 'password_reset', 'smtp_delivery', 'integration', 'backup_restore', 'offline_drill', 'internet_drill', 'server_drill', 'workflow_timing', 'business_baseline', 'business_outcome', 'exit_review'];

    public const FINANCE = ['opening_finance', 'customer_payment', 'supplier_payment', 'supplier_invoice'];

    public function owner(): void
    {
        abort_unless(Auth::user()?->company_id && Auth::user()->role === 'admin', 403);
    }

    public function source(): array
    {
        $blockers = [];
        foreach (['_aims_synthetic_manifest', '_aims_performance_manifest'] as $table) {
            if (Schema::hasTable($table)) {
                $blockers[] = 'test_database';
            }
        }
        $demo = User::where('company_id', Auth::user()->company_id)->where(function ($q) {
            $q->whereIn('email', ['admin@enterprise.com', 'manager@enterprise.com', 'staff@enterprise.com'])
                ->orWhere('email', 'like', '%@%.test')->orWhere('email', 'like', '%@aims-demo.test');
        })->exists();
        if ($demo) {
            $blockers[] = 'demo_accounts';
        }
        $attestation = PilotProfile::first()?->source_attestation;
        $real = ($attestation['declared_real'] ?? false) && ! $blockers;

        return ['state' => $blockers ? 'SYNTHETIC_OR_DEMO' : ($real ? 'DECLARED_REAL' : 'UNVERIFIED'),
            'real_data_attested' => (bool) $real, 'blockers' => array_values(array_unique($blockers)),
            'basis' => 'Owner attestation with source documents; not automated proof of provenance.'];
    }

    public function save(array $input): PilotProfile
    {
        $this->owner();

        return DB::transaction(function () use ($input) {
            DB::table('companies')->where('id', Auth::user()->company_id)->lockForUpdate()->first();
            $profile = PilotProfile::lockForUpdate()->first();
            abort_unless(($profile?->revision ?? 0) === (int) $input['revision'], 409, 'Pilot plan changed. Reload before saving.');
            $profile ??= new PilotProfile;
            $old = $profile->only(['status', 'revision', 'workstreams', 'telemetry_enabled']);
            foreach ($input['participants'] as $participant) {
                abort_unless(User::where('company_id', Auth::user()->company_id)->where('is_active', true)->whereKey($participant['user_id'])->exists(), 422, 'Select an active user from this company.');
            }
            $data = array_intersect_key($input, array_flip(['status', 'cutover_date', 'timezone', 'business_days', 'workstreams', 'participants', 'telemetry_enabled']));
            $data['participants'] = array_map(fn ($p) => ['user_id' => (int) $p['user_id'], 'responsibility' => $p['responsibility'], 'tasks' => PilotPrivacy::text($p['tasks'])], $data['participants']);
            if ($input['attest_real_data'] ?? false) {
                if ($this->source()['blockers'] || empty($input['source_document_ids'])) {
                    throw ValidationException::withMessages(['source_document_ids' => 'Real-company source documents and a database without demo/test markers are required.']);
                }
                foreach ($input['source_document_ids'] as $id) {
                    $this->document($id);
                }
                $data['source_attestation'] = ['declared_real' => true, 'reviewed_by' => Auth::id(), 'reviewed_at' => now()->toIso8601String(), 'document_ids' => $input['source_document_ids']];
            } elseif (array_key_exists('attest_real_data', $input)) {
                $data['source_attestation'] = null;
            }
            $profile->fill($data);
            if ($profile->status === 'active') {
                $errors = [];
                if ((int) config('pilot.company_id') !== (int) Auth::user()->company_id) {
                    $errors[] = 'The operator must select this company with AIMS_PILOT_COMPANY_ID.';
                }
                if (! app()->environment('production')) {
                    $errors[] = 'A real pilot requires the production installation.';
                }
                if (collect(app(ProductionReadinessService::class)->configuration())->contains('status', 'critical')) {
                    $errors[] = 'Resolve production configuration checks before activation.';
                }
                if (! $profile->cutover_date || ! $profile->timezone || ! $profile->business_days || ! $profile->workstreams || ! $profile->participants) {
                    $errors[] = 'Cutover, timezone, calendar, workstreams and participants are required.';
                }
                if ($profile->cutover_date && $profile->cutover_date->toDateString() > CarbonImmutable::now($profile->timezone ?: 'UTC')->toDateString()) {
                    $errors[] = 'Cutover has not occurred.';
                }
                if (! ($profile->source_attestation['declared_real'] ?? false) || $this->source()['blockers']) {
                    $errors[] = 'Real data provenance is unverified.';
                }
                if (! $this->confirmed('opening_inventory')) {
                    $errors[] = 'Reviewed opening stock reconciliation is required.';
                }
                if (in_array('finance', $profile->workstreams ?? []) && ! $this->confirmed('opening_finance')) {
                    $errors[] = 'Independent opening finance approval is required.';
                }
                if (! $this->confirmed('permission_review')) {
                    $errors[] = 'Record a role and task readiness review.';
                }
                if (! $this->confirmed('backup_restore')) {
                    $errors[] = 'Record the separate-environment backup restore drill with its source document.';
                }
                if (! BackupRun::where('company_id', Auth::user()->company_id)->where('operation', 'export')->where('backup_type', 'full')->where('status', 'completed')->where('verification_result', 'checksum_verified')->where('completed_at', '>=', now()->subDays(30))->exists()) {
                    $errors[] = 'Create and reopen a verified full backup before activation.';
                }
                if ($errors) {
                    throw ValidationException::withMessages(['status' => $errors]);
                }
            }
            $profile->revision = ($profile->revision ?? 0) + 1;
            $profile->updated_by = Auth::id();
            $profile->save();
            Cache::forget('pilot-profile:'.Auth::user()->company_id);
            $this->audit('pilot.plan_updated', $profile->id, $old, $profile->only(['status', 'revision', 'workstreams', 'telemetry_enabled']));

            return $profile;
        }, 3);
    }

    public function feedback(array $input): PilotFeedback
    {
        if (! empty($input['entity_type'])) {
            $this->entity($input['entity_type'], $input['entity_id']);
        }

        return PilotFeedback::create([
            'user_id' => Auth::id(), 'role' => Auth::user()->role, 'release_version' => Release::version(),
            'category' => $input['category'], 'page' => PilotPrivacy::page($input['page']),
            'entity_type' => $input['entity_type'] ?? null, 'entity_id' => $input['entity_id'] ?? null,
            'description' => PilotPrivacy::text($input['description'] ?? null), 'reference' => $input['reference'] ?? null,
        ]);
    }

    public function triage(int $id, array $input): PilotFeedback
    {
        $this->owner();

        return DB::transaction(function () use ($id, $input) {
            $row = PilotFeedback::lockForUpdate()->findOrFail($id);
            $row->fill(['priority' => $input['priority'], 'status' => $input['status'], 'resolution' => PilotPrivacy::text($input['resolution'] ?? null), 'triaged_by' => Auth::id(), 'resolved_at' => $input['status'] === 'resolved' ? now() : null])->save();
            $this->audit('pilot.feedback_triaged', $row->id, [], $row->only(['priority', 'status']));

            return $row;
        }, 3);
    }

    public function evidence(array $input): PilotEvidence
    {
        $this->evidenceAccess($input['kind']);
        if (! empty($input['document_id'])) {
            $this->document($input['document_id']);
        }
        if (in_array($input['kind'], ['opening_inventory', 'opening_finance', 'backup_restore', 'source_review', 'business_baseline', 'business_outcome']) && empty($input['document_id'])) {
            throw ValidationException::withMessages(['document_id' => 'Attach the authoritative source or drill report from Document Center.']);
        }
        if (! empty($input['entity_type'])) {
            $this->entity($input['entity_type'], $input['entity_id']);
        }
        $input['checks'] = array_map(fn ($check) => ['name' => PilotPrivacy::text($check['name']), 'result' => $check['result']], $input['checks']);
        if ($input['outcome'] === 'confirmed' && collect($input['checks'])->contains(fn ($check) => in_array($check['result'], ['failed', 'not_tested']))) {
            throw ValidationException::withMessages(['outcome' => 'Unresolved or untested checks cannot be confirmed.']);
        }

        return DB::transaction(function () use ($input) {
            $row = PilotEvidence::create(array_intersect_key($input, array_flip(['kind', 'observed_on', 'outcome', 'entity_type', 'entity_id', 'document_id', 'checks'])) + ['prepared_by' => Auth::id(), 'notes' => PilotPrivacy::text($input['notes'] ?? null)]);
            $this->audit('pilot.evidence_recorded', $row->id, [], $row->only(['kind', 'outcome', 'document_id']));

            return $row;
        }, 3);
    }

    public function review(int $id): PilotEvidence
    {
        return DB::transaction(function () use ($id) {
            $row = PilotEvidence::lockForUpdate()->findOrFail($id);
            $this->evidenceAccess($row->kind);
            abort_if($row->is_historical, 422, 'Restored evidence is historical. Record a new local observation.');
            if (! in_array($row->kind, self::FINANCE)) {
                $this->owner();
            }
            abort_if((int) $row->prepared_by === (int) Auth::id(), 422, 'A different authorized person must review this evidence.');
            if ($row->reviewed_at) {
                return $row;
            }
            $row->reviewed_by = Auth::id();
            $row->reviewed_at = now();
            $row->save();
            $this->audit('pilot.evidence_reviewed', $row->id, [], $row->only(['kind', 'outcome']));

            return $row;
        }, 3);
    }

    public function confirmed(string $kind): bool
    {
        $latest = PilotEvidence::where('kind', $kind)->where('is_historical', false)->orderByDesc('observed_on')->orderByDesc('id')->first();

        return $latest?->outcome === 'confirmed' && $latest->reviewed_at !== null;
    }

    public function allowedKinds(): array
    {
        return array_values(array_filter(self::KINDS, function ($kind) {
            try {
                $this->evidenceAccess($kind);

                return true;
            } catch (HttpException) {
                return false;
            }
        }));
    }

    private function evidenceAccess(string $kind): void
    {
        abort_unless(Auth::user()?->company_id, 403);
        $permission = in_array($kind, self::FINANCE) ? 'accounting.reports.view' : match ($kind) {
            'physical_count','goods_receipt','opening_inventory' => 'inventory.view',
            'order_lifecycle' => 'fulfillment.view', 'shipment' => 'shipments.view',
            'assistant_review','workflow_timing' => null,
            default => 'owner',
        };
        if ($permission === 'owner') {
            $this->owner();
        } elseif ($permission) {
            abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, $permission), 403);
        }
    }

    private function document(int $id): void
    {
        abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'documents.view'), 403);
        app(DocumentService::class)->document($id);
    }

    private function entity(string $type, int $id): void
    {
        if ($type === 'inventory-count') {
            abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'inventory.view'), 403);
            InventoryCountSession::findOrFail($id);
        } else {
            app(DocumentEntityRegistry::class)->resolve($type, $id);
        }
    }

    public function audit(string $action, int $id, array $old = [], array $new = []): void
    {
        ActivityLog::create(['user_id' => Auth::id(), 'action' => $action, 'entity' => 'pilot', 'entity_id' => $id, 'description' => 'Pilot review record updated.', 'old_value' => $old, 'new_value' => $new]);
    }
}
