<?php

namespace App\Services\Synthetic;

use App\Models\{AnalyticsPrediction, AnalyticsSnapshot, BusinessEvent, DecisionLearningRecord, FinancialIntelligenceSnapshot, InventoryForecastModel, Product, ShipmentIntelligence, SupplierLeadModel};
use App\Services\{AccountingService, AutomationService, InventoryIntegrityService, IntelligenceAssistantService, SystemIntegrityService};
use Illuminate\Support\Facades\{Cache, DB, Schema};

class SyntheticValidation
{
    public function report(SyntheticCompanyScenario $s, bool $runScenarios = true): array
    {
        if ($runScenarios) $this->planningScenarios($s);
        // Test-only identities use reserved .test addresses; no real verification email is sent.
        foreach ([$s->owner, $s->manager] as $user) if (! $user->email_verified_at) $user->forceFill(['email_verified_at' => now()])->save();
        $counts = [];
        foreach (['products', 'customers', 'suppliers', 'warehouses', 'warehouse_locations', 'sales_orders', 'order_intakes', 'daily_sales', 'daily_sale_items', 'daily_sales_days', 'customer_debt_transactions', 'purchase_requests', 'rfqs', 'supplier_quotes', 'procurement_awards', 'purchase_orders', 'purchase_order_payments', 'goods_receipts', 'shipments', 'shipment_milestones', 'stock_movements', 'stock_transfers', 'inventory_count_sessions', 'quality_inspections', 'landed_costs', 'expenses', 'expense_payments', 'journal_entries', 'financial_account_transactions', 'analytics_snapshots', 'analytics_predictions', 'inventory_forecast_models', 'enterprise_decisions', 'decision_learning_records', 'financial_intelligence_snapshots', 'financial_intelligence_observations', 'customer_sales_snapshots', 'shipment_intelligence', 'supplier_delivery_risks', 'operational_tasks', 'business_events', 'automation_executions', 'documents', 'supply_optimization_plans', 'strategic_simulations'] as $table) if (Schema::hasTable($table)) $counts[$table] = DB::table($table)->count();
        $inventoryIssues = [];
        foreach (['invoices', 'invoice_items', 'financial_accounts', 'goods_receipt_items', 'supplier_invoice_payment_allocations'] as $table) if (Schema::hasTable($table)) $counts[$table] = DB::table($table)->count();
        foreach (Product::all() as $product) {
            $check = app(InventoryIntegrityService::class)->productSnapshot($product);
            if ($check['issues']) $inventoryIssues[$product->sku] = $check;
        }
        $financial = app(AccountingService::class)->integrity();
        $system = app(SystemIntegrityService::class)->snapshot();
        $tenantViolations = [];
        foreach (Schema::getTableListing() as $table) if (Schema::hasColumn($table, 'company_id')) {
            $q = DB::table($table)->whereNotNull('company_id')->where('company_id', '!=', $s->owner->company_id);
            if (str_ends_with($table, 'users')) $q->where(fn ($q) => $q->where('role', '!=', 'superadmin')->orWhere('is_active', true));
            $n = $q->count();
            if ($n) $tenantViolations[$table] = $n;
        }
        $duplicateEffects = [];
        foreach (['stock_movements', 'financial_account_transactions'] as $table) {
            $duplicateEffects[$table] = DB::query()->fromSub(DB::table($table)->whereNotNull('idempotency_key')->select('idempotency_key')->groupBy('company_id', 'idempotency_key')->havingRaw('count(*) > 1'), 'duplicate_keys')->count();
        }
        $before = DB::table('automation_executions')->count();
        if ($event = BusinessEvent::where('event_type', 'inventory.low_stock')->first()) { app(AutomationService::class)->consume($event); app(AutomationService::class)->consume($event); }
        $dedupe = $event !== null && $before === DB::table('automation_executions')->count();
        $assistant = [];
        $operationalBeforeAssistant = $this->operationalHash();
        foreach (['What needs my attention today?', 'Which products are at highest stock risk?', 'Why is Milano 01 at risk?', 'Which supplier should I consider?', 'Which customers owe the most?', 'Which customers may reorder soon?', 'Build the best purchasing plan under €60,000.', 'What if the incoming shipment is 10 days late?'] as $question) {
            $at = microtime(true);
            $answer = app(IntelligenceAssistantService::class)->ask(['question' => $question, 'language' => 'en']);
            $assistant[] = ['question' => $question, 'state' => $answer['state'] ?? null, 'text' => $answer['text'], 'sources' => $answer['sources'], 'limitations' => $answer['limitations'], 'read_only' => $answer['read_only'], 'milliseconds' => round((microtime(true) - $at) * 1000)];
        }
        if ($shipment = \App\Models\Shipment::where('status', 'in_transit')->latest('id')->first()) {
            $question = 'What if this shipment arrives 10 days late?';
            $at = microtime(true);
            $answer = app(IntelligenceAssistantService::class)->ask(['question' => $question, 'language' => 'en', 'entity' => ['type' => 'shipment', 'id' => $shipment->id]]);
            $assistant[] = ['question' => $question, 'shipment_id' => $shipment->id, 'state' => $answer['state'] ?? null, 'text' => $answer['text'], 'sources' => $answer['sources'], 'limitations' => $answer['limitations'], 'read_only' => $answer['read_only'], 'milliseconds' => round((microtime(true) - $at) * 1000)];
        }
        $search = [];
        foreach (['products' => $s->products[0]['model']->name, 'customers' => $s->customers[0]['model']->name, 'suppliers' => $s->suppliers[0]->name, 'sales_orders' => DB::table('sales_orders')->value('order_number'), 'purchase_orders' => DB::table('purchase_orders')->value('po_number'), 'shipments' => DB::table('shipments')->value('tracking_number'), 'documents' => DB::table('documents')->value('title'), 'decisions' => $s->products[0]['model']->name] as $group => $term) {
            $at = microtime(true);
            $found = app(\App\Services\SearchService::class)->search(mb_substr((string) $term, 0, 80));
            $search[$group] = ['term' => $term, 'matches' => count($found[$group] ?? []), 'milliseconds' => round((microtime(true) - $at) * 1000), 'links' => array_column($found[$group] ?? [], 'url')];
        }
        $s->notes['global_search'] = $search;
        $leakage = DB::table('inventory_forecast_models')->whereRaw('date(training_cutoff) >= date(created_at)')->count();
        $futureAnchors = DB::table('analytics_predictions as p')->join('analytics_snapshots as s', 's.id', '=', 'p.analytics_snapshot_id')->whereRaw('datetime(s.observed_at) > datetime(p.generated_at)')->count();
        $s->notes['future_prediction_anchor_violations'] = $futureAnchors;
        $s->notes['assistant_operational_state_unchanged'] = hash_equals($operationalBeforeAssistant, $this->operationalHash());
        // Assistant optimizer runs are advisory records. Counts include those runs too.
        foreach (array_keys($counts) as $table) $counts[$table] = DB::table($table)->count();
        $leakage += $futureAnchors;
        $readiness = $this->readiness($s);
        return ['configuration' => $s->config, 'timeline' => ['start' => $s->start->toDateString(), 'end' => $s->config['end_date'], 'chronological' => true], 'counts' => $counts, 'business_states' => ['orders' => DB::table('sales_orders')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'), 'purchase_orders' => DB::table('purchase_orders')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'), 'shipments' => DB::table('shipments')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')], 'intelligence_readiness' => $readiness, 'scenario_notes' => $s->notes, 'integrity' => ['synthetic_tenant_isolated' => ! $tenantViolations, 'tenant_violations' => $tenantViolations, 'future_data_leakage_prevented' => $leakage === 0, 'future_cutoff_violations' => $leakage, 'inventory_verified' => ! $inventoryIssues, 'inventory_issues' => $inventoryIssues, 'financial_verified' => $financial['reconciliation']['all_reconciled'] && $financial['exceptions']->isEmpty(), 'financial' => $financial, 'system' => $system, 'duplicate_effects' => $duplicateEffects, 'automation_deduplication' => $dedupe], 'assistant' => $assistant, 'limitations' => ['Synthetic scenario is not proof of tax/legal compliance or live AIS accuracy.', 'Closed-period invalid operations are intentionally left to automated tests.', 'Model promotions use existing gates; sparse/cold-start products may remain ineligible.', 'Sunday books without sales remain unknown demand, not fabricated zero-demand labels.']];
    }

    private function operationalHash(): string
    {
        $hash = hash_init('sha256');
        foreach (['products', 'warehouse_stock', 'stock_movements', 'purchase_orders', 'sales_orders', 'customer_debt_transactions', 'journal_entries', 'journal_lines', 'financial_account_transactions'] as $table) {
            hash_update($hash, $table);
            foreach (DB::table($table)->orderBy('id')->cursor() as $row) hash_update($hash, json_encode($row));
        }
        return hash_final($hash);
    }

    /** Current-day floor exercise, after the historical simulation. Never backdate evidence. */
    private function planningScenarios(SyntheticCompanyScenario $s): void
    {
        if ($s->config['days'] < 58) return;
        $s->clock($s->config['days'], '00:10');
        foreach ($s->products as $row) app(\App\Services\InventoryIntelligenceService::class)->refreshProduction($row['model']->id);
        $planning = app(\App\Services\InventoryPlanningService::class);
        $cases = [];
        foreach (array_slice($s->products, 0, 8) as $row) {
            $view = $planning->view($row['model']->id, ['warehouse_id' => $s->warehouses[0]->id]);
            $cases[] = ['product_id' => $row['model']->id, 'state' => $view['plan']['optimization_state'], 'scope' => $view['plan']['scope'], 'transfers' => $view['transfer_opportunities']];
        }
        // A normal bulk putaway leaves the pick warehouse short and reserve warehouse
        // well stocked. Stock totals are unchanged; no fabricated donor demand is added.
        if (! collect($cases)->contains(fn ($row) => count($row['transfers']) > 0)) {
            foreach (array_slice($s->products, 0, 6) as $row) {
                $product = $row['model']->fresh();
                $stock = app(\App\Services\InventorySnapshotService::class)->forProduct($product, null, $s->warehouses[0]->id);
                if ((float) $stock['available_to_promise'] <= (float) $product->min_quantity * 2) continue;
                $quantity = floor((float) $stock['available_to_promise'] * ($s->config['validation_reserve_share'] ?? .85));
                if ($quantity <= 0) continue;
                $operations = app(\App\Services\WarehouseOperationsService::class);
                $transfer = $operations->createTransfer(['source_warehouse_id' => $s->warehouses[0]->id, 'destination_warehouse_id' => $s->warehouses[1]->id, 'destination_location_id' => $s->bins[$s->warehouses[1]->id][0]->id, 'notes' => 'SYNTHETIC current-day bulk putaway: validate reserve-to-pick recommendation, not backdated history.', 'items' => [['product_id' => $product->id, 'quantity' => $quantity]]]);
                $transfer = $operations->dispatch($transfer, $s->key('validation-putaway-dispatch'));
                $operations->receive($transfer, ['idempotency_key' => $s->key('validation-putaway-receive'), 'items' => $transfer->items->map(fn ($item) => ['id' => $item->id, 'accepted_quantity' => $item->quantity, 'damaged_quantity' => 0])->all()]);
                $view = $planning->view($product->id, ['warehouse_id' => $s->warehouses[0]->id]);
                $case = ['date' => today()->toDateString(), 'transfer_id' => $transfer->id, 'product' => $product->name, 'reserve_putaway_quantity' => $quantity, 'transfer_opportunities' => $view['transfer_opportunities'], 'destination_plan' => $view['plan']];
                $s->notes['warehouse_imbalance'] = $case;
                $s->notes['warehouse_imbalances'][] = $case;
                if ($view['transfer_opportunities']) break;
            }
        }
        foreach (array_slice($s->products, 0, 8) as $row) {
            $planning->save($row['model']->id);
            $planning->save($row['model']->id, ['warehouse_id' => $s->warehouses[0]->id]);
        }
        $s->notes['planning_cases_before_current_day_putaway'] = $cases;
        // Fresh advisory views for the demo login; earlier predictions/responses and
        // outcomes remain immutable. New decisions have no invented future outcome.
        $s->clock($s->config['days'], '00:20');
        foreach ($s->products as $row) app(\App\Services\EnterpriseDecisionService::class)->refresh($row['model']->id);
        app(\App\Services\CustomerSalesIntelligenceService::class)->refresh();
        foreach ($s->suppliers as $supplier) app(\App\Services\SupplierIntelligenceService::class)->refresh($supplier->id);
        app(\App\Services\FinancialIntelligenceService::class)->refresh();
        $s->notes['current_day_advisory_refresh'] = today()->toDateString();
        $optimizer = app(\App\Services\SupplyOptimizerService::class);
        foreach ($s->config['optimizer_budgets'] ?? [500, 60000] as $budget) {
            $plan = $optimizer->submit(['horizon' => 30, 'product_ids' => array_map(fn ($row) => $row['model']->id, array_slice($s->products, 0, 8)), 'warehouse_ids' => [$s->warehouses[0]->id, $s->warehouses[1]->id], 'commitment_limit' => $budget, 'allow_transfers' => true, 'protect_critical' => true, 'transfer_lead_days' => 1]);
            $optimizer->run($plan['id']);
            $s->notes['current_day_optimizer'][$budget] = $optimizer->get($plan['id']);
        }
        $this->currentDayQualifiedScenarios($s);
    }

    /** Keep the unsupported full scope visible; compare an explicitly qualified scope too. */
    public function currentDayQualifiedScenarios(SyntheticCompanyScenario $s): void
    {
        $before = $this->operationalHash();
        $planning = app(\App\Services\InventoryPlanningService::class);
        $ids = $excluded = [];
        foreach (array_slice($s->products, 0, 8) as $row) {
            $plan = $planning->view($row['model']->id, ['warehouse_id' => $s->warehouses[0]->id])['plan'];
            if ($plan['coverage_supported']) $ids[] = $row['model']->id;
            else $excluded[] = ['product_id' => $row['model']->id, 'name' => $row['model']->name, 'reason' => 'Recorded history cannot support dated coverage; not silently treated as zero demand.'];
        }
        $s->notes['qualified_scope'] = ['product_ids' => $ids, 'warehouse_ids' => [$s->warehouses[0]->id], 'excluded' => $excluded, 'qualification' => 'Explicit smaller comparison, not proof that the entire mixed-history scope is feasible.'];
        if (! $ids) return;
        $optimizer = app(\App\Services\SupplyOptimizerService::class);
        foreach ($s->config['optimizer_budgets'] ?? [500, 60000] as $budget) {
            $plan = $optimizer->submit(['horizon' => 30, 'product_ids' => $ids, 'warehouse_ids' => [$s->warehouses[0]->id], 'commitment_limit' => $budget, 'allow_transfers' => true, 'protect_critical' => true, 'transfer_lead_days' => 1]);
            $optimizer->run($plan['id']);
            $s->notes['qualified_scope_optimizer'][$budget] = $optimizer->get($plan['id']);
        }
        $simulation = app(\App\Services\StrategicSimulationService::class);
        $run = $simulation->submit(['name' => 'PM3 qualified dispatch network stress comparison', 'horizon' => 30, 'scope' => ['product_ids' => $ids, 'warehouse_ids' => [$s->warehouses[0]->id], 'allow_transfers' => true], 'assumptions' => [['type' => 'demand', 'action' => 'percent', 'value' => 20], ['type' => 'supplier', 'action' => 'lead_days', 'supplier_id' => $s->suppliers[2]->id, 'days' => 10], ['type' => 'logistics', 'action' => 'delay', 'days' => 10], ['type' => 'financial', 'action' => 'collections_delay', 'days' => 14]], 'optimize' => true]);
        $simulation->run($run['id']);
        $result = $simulation->get($run['id']);
        $s->notes['qualified_scope_v12'] = ['id' => $run['id'], 'status' => $result['status'], 'error' => $result['error'], 'operational_state_unchanged' => hash_equals($before, $this->operationalHash())];
    }

    private function readiness(SyntheticCompanyScenario $s): array
    {
        $supplier = [];
        foreach ($s->suppliers as $vendor) {
            $rows = app(\App\Services\SupplierHistoryService::class)->orders($vendor);
            $supplier[$vendor->name] = ['orders' => count($rows), 'completed' => collect($rows)->where('complete', true)->count(), 'ml_eligible' => collect($rows)->where('complete', true)->where('ml_eligible', true)->count()];
        }
        return [
            'observations_by_type' => AnalyticsSnapshot::selectRaw('entity_type, count(*) as n')->groupBy('entity_type')->pluck('n', 'entity_type'),
            'forecast_eligible_observations' => AnalyticsSnapshot::where('entity_type', 'demand_observation')->where('facts->complete', true)->where('facts->stockout', false)->count(),
            'forecast_models_by_status' => InventoryForecastModel::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'supplier_models_by_status' => SupplierLeadModel::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'predictions_by_domain' => AnalyticsPrediction::selectRaw('model_key, count(*) as n')->groupBy('model_key')->pluck('n', 'model_key'),
            'forecast_windows_evaluated' => AnalyticsPrediction::where('model_key', 'inventory-demand-v1')->whereNotNull('evaluated_at')->count(),
            'forecast_windows_complete' => AnalyticsPrediction::where('model_key', 'inventory-demand-v1')->where('evaluation->eligible', true)->count(),
            'supplier_histories' => $supplier,
            'shipment_outcomes' => ShipmentIntelligence::whereNotNull('outcome')->count(),
            'shipment_eligible_outcomes' => ShipmentIntelligence::where('outcome->eligible', true)->count(),
            'financial_cash_observations' => DB::table('financial_intelligence_observations')->count(),
            'financial_evaluated_snapshots' => FinancialIntelligenceSnapshot::whereNotNull('evaluation')->count(),
            'decision_learning_by_kind' => DecisionLearningRecord::selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind'),
            'decisions_by_status' => DB::table('enterprise_decisions')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'no_lowered_promotion_gates' => ['minimum_observed_days' => config('inventory_intelligence.promotion_min_days'), 'minimum_validation_windows' => config('inventory_intelligence.promotion_min_windows'), 'minimum_recent_coverage' => config('inventory_intelligence.promotion_min_coverage'), 'supplier_minimum_completed_orders' => config('supplier_intelligence.minimum_training_orders')],
        ];
    }
}
