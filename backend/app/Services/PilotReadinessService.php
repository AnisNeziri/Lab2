<?php

namespace App\Services;

use App\Models\AnalyticsSnapshot;
use App\Models\DailySale;
use App\Models\InventoryForecastModel;
use App\Models\PilotEvidence;
use App\Models\PilotFeedback;
use App\Models\PilotProfile;
use App\Models\PilotReport;
use App\Models\SupplierLeadModel;
use App\Support\Release;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Read-only observations. Viewing readiness never captures history or trains/promotes models. */
class PilotReadinessService
{
    private function table(string $table)
    {
        return DB::table($table)->where($table.'.company_id', Auth::user()->company_id);
    }

    private function rate(?int $numerator, ?int $denominator): array
    {
        return ['count' => $numerator, 'total' => $denominator, 'percent' => $denominator && $numerator !== null ? round(100 * $numerator / $denominator, 1) : null];
    }

    public function report(): array
    {
        app(PilotService::class)->owner();
        $profile = PilotProfile::first();
        $source = app(PilotService::class)->source();
        $end = CarbonImmutable::now($profile?->timezone ?: 'UTC')->startOfDay()->subDay();
        $cutover = $profile?->cutover_date ? CarbonImmutable::parse($profile->cutover_date->toDateString(), $end->timezone) : null;
        $start = $cutover && $cutover->lte($end) ? $cutover->max($end->subDays(89)) : null;
        $days = [];
        if ($start && $start->lte($end) && $profile->business_days) {
            for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                if (in_array($day->dayOfWeekIso, $profile->business_days)) {
                    $days[] = $day->toDateString();
                }
            }
        }
        $sales = DailySale::where('status', 'finalized');
        if ($start) {
            $sales->whereBetween('sale_date', [$start->toDateString(), $end->toDateString()]);
        } else {
            $sales->whereRaw('1 = 0');
        }
        $recorded = (clone $sales)->distinct()->pluck('sale_date')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $observed = array_values(array_intersect($days, $recorded));
        $totalSales = $start ? (clone $sales)->count() : null;
        $products = $this->table('products')->whereNull('archived_at');
        $productCount = (clone $products)->count();
        $pos = $this->table('purchase_orders')->whereNull('deleted_at');
        $poCount = (clone $pos)->count();
        $receipts = $this->table('goods_receipts');
        $receiptCount = (clone $receipts)->count();
        $ships = $this->table('shipments')->whereNull('archived_at');
        $shipCount = (clone $ships)->count();
        $debts = $this->table('customer_debt_transactions')->where('type', 'debt');
        $debtCount = (clone $debts)->count();
        $issues = $this->table('analytics_issues')->whereNull('resolved_at')->select('code')->selectRaw('COUNT(*) as count')->groupBy('code')->orderByDesc('count')->limit(25)->get();
        $negative = (clone $products)->where('quantity', '<', 0)->count();
        $missingUnit = (clone $products)->where(fn ($q) => $q->whereNull('unit')->orWhereRaw("TRIM(unit) = ''"))->count();
        $missingWarehouse = (clone $products)->whereNull('default_warehouse_id')->count();
        $missingPo = (clone $ships)->whereNull('purchase_order_id')->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('shipment_purchase_orders as spo')->whereColumn('spo.shipment_id', 'shipments.id')->whereColumn('spo.company_id', 'shipments.company_id');
        })->count();
        $missingDebtDate = (clone $debts)->whereNull('due_date')->count();
        $missingReceiptDate = (clone $receipts)->whereNull('received_at')->count();
        $observations = AnalyticsSnapshot::where('entity_type', 'demand_observation');
        if ($start) {
            $observations->whereBetween('snapshot_date', [$start->toDateString(), $end->toDateString()]);
        } else {
            $observations->whereRaw('1 = 0');
        }
        $forecast = $this->models('inventory_demand', $source['real_data_attested']);
        $supplier = $this->models('supplier_lead', $source['real_data_attested']);
        $gaps = [
            ['code' => 'missing_unit', 'count' => $missingUnit, 'owner' => 'warehouse', 'url' => '/products'],
            ['code' => 'missing_warehouse', 'count' => $missingWarehouse, 'owner' => 'warehouse', 'url' => '/products'],
            ['code' => 'negative_stock', 'count' => $negative, 'owner' => 'warehouse', 'url' => '/system-integrity'],
            ['code' => 'shipment_missing_po', 'count' => $missingPo, 'owner' => 'purchasing', 'url' => '/shipments/my-shipments'],
            ['code' => 'receipt_missing_actual_date', 'count' => $missingReceiptDate, 'owner' => 'purchasing', 'url' => '/warehouse-operations'],
            ['code' => 'debt_missing_due_date', 'count' => $missingDebtDate, 'owner' => 'finance', 'url' => '/customer-debts'],
        ];

        return [
            'captured_at' => now()->toIso8601String(), 'release' => Release::version(), 'source' => $source, 'profile' => $profile,
            'period' => ['start' => $start?->toDateString(), 'end' => $end->toDateString(), 'calendar' => 'Configured ISO weekdays; holidays require owner review.', 'elapsed_days' => $cutover ? max(0, (int) $cutover->diffInDays($end, false) + 1) : 0],
            'sales' => [
                'business_days_with_finalized_sales' => $this->rate($start && $profile->business_days ? count($observed) : null, $start && $profile->business_days ? count($days) : null),
                'unknown_days' => array_values(array_diff($days, $observed)), 'missing_days_are_zero' => false,
                'sheets_with_product_lines' => $this->rate($start ? (clone $sales)->whereHas('items', fn ($q) => $q->whereNotNull('product_id'))->count() : null, $totalSales),
                'customer_where_expected' => null, 'customer_basis' => 'Walk-in sales may omit customers. Confirm the company policy before measuring expected attribution.',
                'warehouse_attribution' => null, 'warehouse_basis' => 'Check dispatch and stock-movement attribution; sales sheets have no warehouse field.',
                'known_stockout_observations' => $start ? (clone $observations)->where('facts->stockout', true)->count() : null,
                'finalized_sheets' => $totalSales, 'orders' => $this->table('sales_orders')->count(),
            ],
            'inventory' => [
                'products_with_unit' => $this->rate($productCount - $missingUnit, $productCount),
                'products_with_default_warehouse' => $this->rate($productCount - $missingWarehouse, $productCount),
                'products_with_location' => $this->rate((clone $products)->whereNotNull('location_code')->where('location_code', '!=', '')->count(), $productCount),
                'latest_approved_count' => $this->table('inventory_count_sessions')->where('status', 'approved')->max('approved_at'),
                'negative_stock_exceptions' => $negative, 'integrity_issues' => $issues,
                'current_snapshot_coverage' => null, 'basis' => 'A product quantity is not proof of a current physical count. Use System Integrity and opening/count evidence.',
            ],
            'procurement' => [
                'orders_with_supplier' => $this->rate((clone $pos)->whereNotNull('supplier_id')->count(), $poCount),
                'orders_with_expected_date' => $this->rate((clone $pos)->whereNotNull('expected_at')->count(), $poCount),
                'receipts_with_po' => $this->rate((clone $receipts)->whereNotNull('purchase_order_id')->count(), $receiptCount),
                'receipts_with_actual_date' => $this->rate($receiptCount - $missingReceiptDate, $receiptCount),
                'partial_receipt_correctness' => null, 'basis' => 'PO dates apply at order level. Partial receipt quantities and payment links require reconciliation, not a non-null check.',
            ],
            'supplier' => ['models' => $supplier, 'completed_orders' => (clone $pos)->whereNotNull('received_at')->count(),
                'eligible_training_rows' => null, 'basis' => 'Completed orders are not automatically ML-eligible. Existing Supplier History exclusions and validation gates still apply.',
                'required_training_orders' => config('supplier_intelligence.minimum_training_orders'), 'required_validation_orders' => config('supplier_intelligence.minimum_validation_orders')],
            'shipments' => [
                'with_po' => $this->rate($shipCount - $missingPo, $shipCount),
                'with_route' => $this->rate((clone $ships)->whereNotNull('origin_port')->where('origin_port', '!=', '')->whereNotNull('destination_port')->where('destination_port', '!=', '')->count(), $shipCount),
                'with_actual_arrival' => $this->rate((clone $ships)->whereNotNull('arrival_date')->count(), $shipCount),
                'latest_position_update' => (clone $ships)->max('position_updated_at'), 'milestone_completeness' => null,
                'basis' => 'Arrival coverage includes open shipments. A recent provider refresh does not prove a live AIS position.',
            ],
            'customers' => [
                'customers' => $this->table('customers')->whereNull('deleted_at')->count(),
                'transaction_history_rows' => $this->table('customer_debt_transactions')->count(),
                'receivables_with_due_date' => $this->rate($debtCount - $missingDebtDate, $debtCount),
                'payment_timing_quality' => null, 'repeat_order_quality' => null,
            ],
            'finance' => [
                'account_transaction_rows' => $this->table('financial_account_transactions')->count(),
                'reviewed_opening_finance' => app(PilotService::class)->confirmed('opening_finance'),
                'source_coverage' => null, 'cashflow' => null,
                'basis' => 'Recorded transactions do not prove full bank/cash coverage. No cashflow is inferred from incomplete opening balances.',
            ],
            'forecast' => ['observations' => $start ? (clone $observations)->count() : null, 'complete_observations' => $start ? (clone $observations)->where('facts->complete', true)->count() : null,
                'evaluated_predictions' => $this->table('analytics_predictions')->where('model_key', 'inventory-demand-v1')->whereNotNull('evaluated_at')->count(),
                'models' => $forecast, 'required_observed_days' => config('inventory_intelligence.promotion_min_days'), 'required_windows' => config('inventory_intelligence.promotion_min_windows'),
                'bias' => null, 'mae' => null, 'wape' => null, 'basis' => 'Model metrics below come from existing holdout evidence. Overlapping live windows are not pooled into invented accuracy.'],
            'adoption' => app(PilotTelemetryService::class)->summary($start?->toDateString(), $end->toDateString()),
            'data_gaps' => array_values(array_filter($gaps, fn ($g) => $g['count'] > 0)),
            'feedback' => PilotFeedback::select('priority', 'status')->selectRaw('COUNT(*) as count')->groupBy('priority', 'status')->get(),
            'reviewed_evidence' => PilotEvidence::where('is_historical', false)->whereNotNull('reviewed_at')->select('kind', 'outcome')->selectRaw('COUNT(*) as count')->groupBy('kind', 'outcome')->get(),
            'exit' => ['real_pilot_completed' => false, 'ready_for_primary_use' => false, 'basis' => 'Exit requires owner review of 8–12 weeks of real operation, reconciliations, restore evidence and routine user trials. A scorecard never certifies this automatically.'],
        ];
    }

    private function models(string $domain, bool $real): array
    {
        $query = InventoryForecastModel::where('domain', $domain)->whereIn('status', ['active', 'candidate']);
        $count = (clone $query)->count();
        $models = $query->latest('id')->limit(100)->get();
        $rows = [];
        foreach ($models as $model) {
            // Recheck the original promotion context for an active model, not itself as incumbent.
            $version = $model->status === 'active' ? ($model->review['production_version'] ?? null) : InventoryForecastModel::where('domain', $domain)->where('product_id', $model->product_id)->where('supplier_id', $model->supplier_id)->where('horizon', $model->horizon)->where('status', 'active')->value('version');
            $incumbent = $version ? InventoryForecastModel::where('domain', $domain)->where('version', $version)->first() : null;
            $gate = $domain === 'supplier_lead'
                ? app(SupplierLearningService::class)->gates((new SupplierLeadModel)->setRawAttributes($model->getAttributes(), true), $incumbent ? (new SupplierLeadModel)->setRawAttributes($incumbent->getAttributes(), true) : null)
                : app(InventoryLearningService::class)->gates($model, $incumbent);
            $artifactValid = false;
            try {
                app(InventoryLearningService::class)->checkArtifact($model);
                $artifactValid = true;
            } catch (\Throwable) { /* Expose a safe reason, never an artifact or exception message. */
            }
            $qualified = $real && $model->status === 'active' && $gate['eligible'] && $artifactValid;
            $rows[] = ['id' => $model->id, 'product_id' => $model->product_id, 'supplier_id' => $model->supplier_id, 'horizon' => $model->horizon,
                'state' => $qualified ? 'QUALIFIED' : (($model->metrics['blocks'] ?? $model->comparison['challenger']['samples'] ?? 0) > 0 ? 'EVALUATING' : 'COLLECTING'),
                'status' => $model->status, 'observed_days' => $model->quality['observed_days'] ?? null,
                'completed_windows' => $model->metrics['blocks'] ?? null, 'mae' => $model->metrics['mae'] ?? $model->comparison['challenger']['mae'] ?? null,
                'coverage' => $gate['recent_coverage'] ?? null,
                'reasons' => array_values(array_merge($gate['reasons'], $real ? [] : ['real_data_unverified'], $artifactValid ? [] : ['artifact_invalid']))];
        }

        return ['state' => $count ? (collect($rows)->contains('state', 'QUALIFIED') ? 'QUALIFIED' : (collect($rows)->contains('state', 'EVALUATING') ? 'EVALUATING' : 'COLLECTING')) : 'INSUFFICIENT', 'total' => $count, 'shown' => count($rows), 'rows' => $rows];
    }

    public function snapshot(string $kind): PilotReport
    {
        $report = $this->report();
        // Retry of the same report payload is idempotent; earlier snapshots cannot be edited.
        unset($report['captured_at']);
        $fingerprint = hash('sha256', json_encode([$kind, $report], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($kind, $report, $fingerprint) {
            $row = PilotReport::firstOrCreate(['fingerprint' => $fingerprint], ['kind' => $kind, 'period_start' => $report['period']['start'] ?: $report['period']['end'], 'period_end' => $report['period']['end'], 'captured_at' => now(), 'report' => $report, 'captured_by' => Auth::id()]);
            if ($row->wasRecentlyCreated) {
                app(PilotService::class)->audit('pilot.report_captured', $row->id, [], ['kind' => $kind]);
            }

            return $row;
        }, 3);
    }
}
