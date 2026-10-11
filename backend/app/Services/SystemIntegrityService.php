<?php

namespace App\Services;

use App\Models\AccountingException;
use App\Models\BackupRun;
use App\Models\IntegrationOperationLog;
use App\Models\IntegrationProvider;
use App\Models\OperationalException;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemIntegrityService
{
    public function snapshot(): array
    {
        $companyId = (int) Auth::user()->company_id;
        $databaseOnline = $this->databaseOnline();
        $checks = [
            $this->check('database', 'Database', $databaseOnline ? 'healthy' : 'critical', $databaseOnline ? 0 : 1, '/dashboard', 'Primary database connection'),
            $this->countCheck('failed_jobs', 'Failed background jobs', Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0, '/activity-logs'),
            $this->countCheck('failed_webhooks', 'Failed webhook deliveries', Schema::hasTable('webhook_deliveries') ? WebhookDelivery::query()->where('status', 'failed')->count() : 0, '/control-tower'),
            $this->countCheck('integration_failures', 'Integration failures', IntegrationProvider::query()->where('enabled', true)->whereIn('health_state', ['error', 'degraded'])->count(), '/control-tower'),
            $this->countCheck('integration_operations', 'Failed integration operations', IntegrationOperationLog::query()->where('status', 'failed')->where(fn ($q) => $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))->count(), '/control-tower'),
            $this->countCheck('operational_exceptions', 'Open operational exceptions', OperationalException::query()->whereIn('status', ['open', 'active'])->count(), '/control-tower'),
            $this->countCheck('accounting_exceptions', 'Open accounting exceptions', Schema::hasTable('accounting_exceptions') ? AccountingException::query()->where('status', 'open')->count() : 0, '/accounting?tab=integrity'),
            $this->inventoryBalanceCheck($companyId),
            $this->backupRecencyCheck($companyId),
            $this->backupFailureCheck($companyId),
            $this->restoreDrillCheck(),
            $this->analyticsHealthCheck($companyId),
            $this->relationshipIntegrityCheck($companyId),
            $this->countCheck('outbound_quantities','Outbound quantity integrity',\App\Models\OutboundAllocation::query()->where(fn($q)=>$q->whereColumn('picked_quantity','>','quantity')->orWhereColumn('packed_quantity','>','picked_quantity')->orWhereColumn('dispatched_quantity','>','packed_quantity')->orWhereColumn('delivered_quantity','>','dispatched_quantity')->orWhereColumn('returned_quantity','>','delivered_quantity'))->count(),'/fulfillment'),
        ];
        if (Schema::hasTable('automation_executions')) {
            $checks[]=$this->countCheck('automation_failures','Failed or blocked automations',\App\Models\AutomationExecution::whereIn('status',['failed','blocked'])->count(),'/automation-studio');
            $checks[]=$this->countCheck('automation_stuck','Stuck automation executions',\App\Models\AutomationExecution::whereIn('status',['queued','running'])->where('updated_at','<',now()->subMinutes(15))->count(),'/automation-studio');
            $invalid=0;
            $registry=app(AutomationRegistry::class);
            foreach(\App\Models\Automation::where('enabled',true)->get() as $rule) {
                $trigger=$registry->triggers()[$rule->trigger] ?? null;
                $invalidActions=collect($rule->actions)->contains(fn($a)=>!isset($registry->actions()[$a['type'] ?? '']));
                $creator=\App\Models\User::where('company_id',$rule->company_id)->find($rule->created_by);
                if(!$trigger || $invalidActions || !$creator?->is_active) $invalid++;
            }
            $checks[]=$this->countCheck('automation_configuration','Invalid automation configuration',$invalid,'/automation-studio');
        }
        if(Schema::hasTable('document_versions')) $checks[]=$this->countCheck('document_integrity','Document integrity (last verification)',\App\Models\DocumentVersion::query()->whereNotNull('integrity_status')->where('integrity_status','!=','valid')->count(),'/documents');
        if(Schema::hasTable('order_intakes')){
            $checks[]=$this->countCheck('order_intake_review','Order intake requires review',\App\Models\OrderIntake::where('state','attention')->count(),'/order-hub?view=attention');
            $checks[]=$this->countCheck('order_intake_orphan','Intakes missing their linked sales order',\App\Models\OrderIntake::whereNotNull('sales_order_id')->whereDoesntHave('order')->count(),'/order-hub');
            $checks[]=$this->countCheck('order_intake_stuck','Order intake has not progressed for a day',\App\Models\OrderIntake::where('state','received')->where('updated_at','<',now()->subDay())->count(),'/order-hub?view=attention');
        }
        if(Schema::hasTable('documents')){
            $missing=DB::table('documents as d')->where('d.company_id',$companyId)->whereNotExists(fn($q)=>$q->selectRaw('1')->from('document_versions as v')->whereColumn('v.document_id','d.id')->whereColumn('v.company_id','d.company_id')->whereColumn('v.version','d.current_version'))->count();
            $checks[]=$this->countCheck('document_current_version','Documents missing their current version',$missing,'/documents');
            $broken=DB::table('document_links')->where('company_id',$companyId)->whereNotIn('entity_type',array_keys(DocumentEntityRegistry::TYPES))->count();
            foreach(DocumentEntityRegistry::TYPES as $type=>[$model,$table]){
                $broken+=DB::table('document_links as l')->where('l.company_id',$companyId)->where('l.entity_type',$type)->whereNotExists(fn($q)=>$q->selectRaw('1')->from($table.' as e')->whereColumn('e.id','l.entity_id')->whereColumn('e.company_id','l.company_id'))->count();
            }
            $checks[]=$this->countCheck('document_relationships','Broken document relationships',$broken,'/documents');
        }
        $checks[]=$this->countCheck('outbound_missing_sale','Dispatches missing their authoritative sale',\App\Models\OutboundDispatch::query()->whereNull('daily_sale_id')->count(),'/fulfillment');
        if (app()->environment('production')) {
            foreach (app(ProductionReadinessService::class)->inspect()['checks'] as $row) {
                $checks[] = $this->check('production_'.$row['key'], ucwords(str_replace('_', ' ', $row['key'])), $row['status'], $row['status'] === 'critical' ? 1 : 0, '/system-integrity', $row['detail']);
            }
        }
        $critical = collect($checks)->where('status', 'critical')->count();
        $attention = collect($checks)->where('status', 'attention')->count();

        return [
            'status' => $critical > 0 ? 'critical' : ($attention > 0 ? 'attention' : 'healthy'),
            'checked_at' => now()->toIso8601String(),
            'request_id' => request()->attributes->get('request_id'),
            'summary' => ['healthy' => collect($checks)->where('status', 'healthy')->count(), 'attention' => $attention, 'critical' => $critical],
            'checks' => $checks,
            'backup_history' => Schema::hasTable('backup_runs')
                ? BackupRun::query()->where('company_id', $companyId)
                    ->orderByDesc('started_at')->orderByDesc('id')->limit(20)->get()->values()->all()
                : [],
        ];
    }

    private function backupRecencyCheck(int $companyId): array
    {
        if (! Schema::hasTable('backup_runs')) {
            return $this->check('backup_recency', 'Recent verified backup', 'attention', 1, '/reports', 'Backup history is not initialized.');
        }
        $recent = BackupRun::query()->where('company_id', $companyId)->where('status', 'completed')->where('operation', 'export')->where('backup_type', 'full')
            ->where('verification_result', 'checksum_verified')
            ->where('completed_at', '>=', now()->subDays(30))->exists();

        return $this->check('backup_recency', 'Recent verified full backup', $recent ? 'healthy' : 'attention', $recent ? 0 : 1, '/reports', $recent ? 'A full backup was reopened and verified within the last 30 days.' : 'No reopened and verified full backup completed within the last 30 days.');
    }

    private function restoreDrillCheck(): array
    {
        $file = storage_path('framework/aims-backup-health.json');
        $health = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        $last = $health['last_restore_verified_at'] ?? null;
        $fresh = $last && \Carbon\Carbon::parse($last)->gte(now()->subDays(30));
        return $this->check('restore_drill', 'Independent installation restore', $fresh ? 'healthy' : 'attention', $fresh ? 0 : 1, '/reports', $fresh ? 'This installation passed restoration, attachment verification and inventory/finance reconciliation.' : 'No successful independent installation restore recorded within 30 days. Test an encrypted backup on an empty Installation B.')
            + ['last_success_at' => $last, 'last_verified_backup_at' => $health['last_verified_at'] ?? null, 'last_backup_failure_at' => $health['last_failure_at'] ?? null];
    }

    private function analyticsHealthCheck(int $companyId): array
    {
        $run = Schema::hasTable('maintenance_health')
            ? DB::table('maintenance_health')->where('company_id', $companyId)->where('task', 'analytics_capture')->first() : null;
        $lastObservation = Schema::hasTable('analytics_snapshots')
            ? DB::table('analytics_snapshots')->where('company_id', $companyId)->where('entity_type', 'backlog')->max('observed_at') : null;
        $fresh = $lastObservation && \Carbon\Carbon::parse($lastObservation)->gte(now()->subHours(36));
        $failed = $run?->status === 'failed';
        $detail = $failed ? 'The latest scheduled analytics capture failed. Review permissions and the backend log.'
            : ($fresh ? 'Analytics observations are current.' : 'No completed analytics observation in the last 36 hours. Check the scheduler.');
        return $this->check('analytics_capture', 'Analytics capture health', $failed || !$fresh ? 'attention' : 'healthy', $failed || !$fresh ? 1 : 0, '/analytics', $detail)
            + ['last_success_at' => $run?->last_success_at, 'last_attempt_at' => $run?->last_attempt_at, 'last_observation_at' => $lastObservation, 'error_code' => $run?->error_code];
    }

    private function backupFailureCheck(int $companyId): array
    {
        if (! Schema::hasTable('backup_runs')) {
            return $this->countCheck('backup_failures', 'Recent backup failures', 0, '/reports');
        }
        $failures = BackupRun::query()->where('company_id', $companyId)->where('status', 'failed')
            ->where('started_at', '>=', now()->subDays(30))->count();

        return $this->countCheck('backup_failures', 'Recent backup failures', $failures, '/reports', $failures >= 3);
    }

    private function relationshipIntegrityCheck(int $companyId): array
    {
        $issues = 0;
        $relations = [
            ['goods_receipts', 'purchase_order_id', 'purchase_orders'],
            ['quality_inspections', 'goods_receipt_id', 'goods_receipts'],
            ['supplier_claims', 'goods_receipt_id', 'goods_receipts'],
            ['shipment_containers', 'shipment_id', 'shipments'],
            ['landed_cost_allocations', 'landed_cost_id', 'landed_costs'],
            ['landed_cost_allocations', 'goods_receipt_item_id', 'goods_receipt_items'],
            ['landed_cost_allocations', 'product_id', 'products'],
        ];
        foreach ($relations as [$child, $foreignKey, $parent]) {
            if (! Schema::hasTable($child) || ! Schema::hasTable($parent) || ! Schema::hasColumn($child, $foreignKey)) {
                continue;
            }
            $issues += DB::table($child.' as child')->leftJoin($parent.' as parent', 'parent.id', '=', 'child.'.$foreignKey)
                ->where('child.company_id', $companyId)->whereNotNull('child.'.$foreignKey)->whereNull('parent.id')->count();
        }

        return $this->countCheck('relationship_integrity', 'Critical relationship integrity', $issues, '/system-integrity', true);
    }

    private function inventoryBalanceCheck(int $companyId): array
    {
        if (! Schema::hasTable('warehouse_stock')) {
            return $this->check('inventory_reconciliation', 'Inventory reconciliation', 'attention', 1, '/operations-center', 'Warehouse balances are not initialized.');
        }
        $mismatches = DB::table('warehouse_stock')->where('company_id', $companyId)
            ->whereRaw('ABS(quantity - (available_quantity + reserved_quantity + damaged_quantity + quarantine_quantity + blocked_quantity)) >= 0.0005')
            ->count();

        return $this->countCheck('inventory_reconciliation', 'Inventory state mismatches', $mismatches, '/operations-center', true);
    }

    private function countCheck(string $key, string $label, int $count, string $url, bool $critical = false): array
    {
        return $this->check($key, $label, $count > 0 ? ($critical ? 'critical' : 'attention') : 'healthy', $count, $url, $count > 0 ? 'Review the affected records; AIMS will not silently change business data.' : 'No unresolved issues detected.');
    }

    private function check(string $key, string $label, string $status, int $count, string $url, string $detail): array
    {
        return compact('key', 'label', 'status', 'count', 'url', 'detail');
    }

    private function databaseOnline(): bool
    {
        try { DB::select('select 1'); return true; } catch (\Throwable) { return false; }
    }
}
