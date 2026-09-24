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
            $this->relationshipIntegrityCheck($companyId),
            $this->countCheck('outbound_quantities','Outbound quantity integrity',\App\Models\OutboundAllocation::query()->where(fn($q)=>$q->whereColumn('picked_quantity','>','quantity')->orWhereColumn('packed_quantity','>','picked_quantity')->orWhereColumn('dispatched_quantity','>','packed_quantity')->orWhereColumn('delivered_quantity','>','dispatched_quantity')->orWhereColumn('returned_quantity','>','delivered_quantity'))->count(),'/fulfillment'),
        ];
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
        $recent = BackupRun::query()->where('company_id', $companyId)->where('status', 'completed')
            ->whereIn('verification_result', ['checksum_created', 'checksum_verified'])
            ->where('completed_at', '>=', now()->subDays(30))->exists();

        return $this->check('backup_recency', 'Recent verified backup', $recent ? 'healthy' : 'attention', $recent ? 0 : 1, '/reports', $recent ? 'A verified backup completed within the last 30 days.' : 'No verified backup completed within the last 30 days.');
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
