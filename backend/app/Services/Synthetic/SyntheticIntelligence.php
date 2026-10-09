<?php

namespace App\Services\Synthetic;

use App\Models\{EnterpriseDecision, InventoryForecastModel, Shipment, Supplier, SupplierLeadModel};
use App\Services\{CustomerSalesIntelligenceService, DecisionLearningService, EnterpriseDecisionService, FinancialIntelligenceService, IntelligencePerformanceService, InventoryIntelligenceService, InventoryLearningService, ShipmentIntelligenceService, SupplierIntelligenceService, SupplierLearningService, SupplyOptimizerService, StrategicSimulationService};
use Illuminate\Support\Facades\DB;

class SyntheticIntelligence
{
    public function day(SyntheticCompanyScenario $s, int $day): void
    {
        if ($day === 0) {
            app(FinancialIntelligenceService::class)->policy(['cash_coverage_confirmed' => true, 'minimum_cash' => ['EUR' => 10000]]);
            app(DecisionLearningService::class)->createExperiment(['quantity_multiplier' => 1.1, 'reason' => 'Synthetic owner compares a bounded 10% quantity buffer on FUTURE decisions only. No automatic promotion.']);
        }
        // Point-in-time cash samples every day allow real forward evaluation, not retrospective scores.
        app(FinancialIntelligenceService::class)->refresh();
        if ($day % 7 === 5 || $day === $s->config['days'] - 1) {
            app(CustomerSalesIntelligenceService::class)->refresh();
            foreach ($s->suppliers as $supplier) app(SupplierIntelligenceService::class)->refresh($supplier->id);
            foreach (Shipment::where('status', '!=', 'delivered')->get() as $shipment) app(ShipmentIntelligenceService::class)->refresh($shipment->id);
        }
        if (in_array($day, $s->config['training_days'] ?? [58, 72, 86], true)) {
            foreach ($s->products as $row) {
                if ($row['profile'] === 'cold_start') continue;
                $result = app(InventoryIntelligenceService::class)->train($row['model']->id);
                $s->notes['training'][$day][$row['model']->name] = $result['status'];
                foreach (InventoryForecastModel::where('product_id', $row['model']->id)->where('status', 'candidate')->get() as $model) {
                    $current = app(InventoryLearningService::class)->active($row['model'], $model->horizon);
                    $gate = app(InventoryLearningService::class)->gates($model, $current);
                    if ($gate['eligible']) app(InventoryLearningService::class)->decide($model->id, 'promote', ['reason' => 'Synthetic owner accepts candidate passing unchanged production evidence gates.', 'expected_production_version' => $current?->version]);
                    else $s->notes['model_gates'][$model->id] = $gate['reasons'];
                }
            }
            foreach ($s->suppliers as $supplier) {
                $learning = app(SupplierLearningService::class);
                $s->notes['supplier_training'][$day][$supplier->name] = $learning->train($supplier->id);
                foreach (SupplierLeadModel::where('supplier_id', $supplier->id)->where('status', 'candidate')->get() as $model) {
                    $current = app(SupplierIntelligenceService::class)->active($supplier->id);
                    $gate = $learning->gates($model, $current);
                    if ($gate['eligible']) $learning->decide($model->id, 'promote', ['reason' => 'Synthetic owner accepts supplier candidate passing unchanged evidence gates.', 'expected_production_version' => $current?->version]);
                    else $s->notes['supplier_gates'][$model->id] = $gate['reasons'];
                }
            }
        }
        if ($day >= 59 && $day % 7 === 3) {
            foreach ($s->products as $index => $row) {
                app(InventoryIntelligenceService::class)->refreshProduction($row['model']->id);
                $result = app(EnterpriseDecisionService::class)->refresh($row['model']->id);
                foreach ($result['decisions'] ?? [] as $summary) {
                    $id = $summary['id'];
                    $decision = EnterpriseDecision::find($id);
                    if (in_array($decision->status, ['open', 'reviewed'], true)) {
                        $action = ['accepted', 'modified', 'dismissed'][$index % 3];
                        $linked = false;
                        if ($index < 4 && $action !== 'dismissed' && $decision->decision_type === 'REPLENISHMENT_DECISION') {
                            $option = collect($decision->alternatives)->first(fn ($o) => ($o['action'] ?? null) === 'purchase' && ($o['feasible'] ?? false) && ($o['base_quantity'] ?? 0) > 0);
                            if ($option) {
                                try {
                                    $service = app(EnterpriseDecisionService::class);
                                    $review = $service->review($id, ['alternative_key' => $option['key']]);
                                    $service->draft($id, ['alternative_key' => $option['key'], 'review_token' => $review['review_token'], 'confirm_transfer_assumption' => true]);
                                    $linked = true;
                                } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $e) { $s->notes['decision_draft_constraints'][$id] = $e->getMessage(); }
                            }
                        }
                        if (! $linked) app(EnterpriseDecisionService::class)->feedback($id, ['action' => $action, 'note' => 'Synthetic human policy: review by portfolio group; outcomes are evaluated later, never manually labelled.']);
                    }
                }
            }
        }
        if ($day >= 60) {
            app(IntelligencePerformanceService::class)->evaluate();
            app(DecisionLearningService::class)->maintain(microtime(true) + 15);
        }
    }

    public function finish(SyntheticCompanyScenario $s): void
    {
        if ($s->config['days'] < 58) return;
        app(IntelligencePerformanceService::class)->evaluate();
        app(DecisionLearningService::class)->maintain(microtime(true) + 60);
        app(FinancialIntelligenceService::class)->refresh();
        $this->scenarios($s);
    }

    private function scenarios(SyntheticCompanyScenario $s): void
    {
        $ids = array_map(fn ($r) => $r['model']->id, array_slice($s->products, 0, 8));
        $optimizer = app(SupplyOptimizerService::class);
        $plan = $optimizer->submit(['horizon' => 30, 'product_ids' => $ids, 'warehouse_ids' => [$s->warehouses[0]->id, $s->warehouses[1]->id], 'commitment_limit' => 500, 'allow_transfers' => true, 'protect_critical' => true, 'transfer_lead_days' => 1]);
        $optimizer->run($plan['id']);
        $result = $optimizer->get($plan['id']);
        $s->notes['optimizer'] = ['id' => $plan['id'], 'status' => $result['status'], 'error' => $result['error'], 'result' => $result['result']];
        $comparison = $optimizer->submit(['horizon' => 30, 'product_ids' => $ids, 'warehouse_ids' => [$s->warehouses[0]->id, $s->warehouses[1]->id], 'commitment_limit' => 60000, 'allow_transfers' => true, 'protect_critical' => true, 'transfer_lead_days' => 1]);
        $optimizer->run($comparison['id']);
        $s->notes['optimizer_normal_budget'] = $optimizer->get($comparison['id']);
        $before = $this->operationalHash();
        $simulation = app(StrategicSimulationService::class);
        $run = $simulation->submit(['name' => 'PM3 demand growth and delayed collections', 'horizon' => 30, 'scope' => ['product_ids' => $ids, 'warehouse_ids' => [$s->warehouses[0]->id, $s->warehouses[1]->id], 'allow_transfers' => true], 'assumptions' => [['type' => 'demand', 'action' => 'percent', 'value' => 20], ['type' => 'supplier', 'action' => 'lead_days', 'supplier_id' => $s->suppliers[2]->id, 'days' => 10], ['type' => 'logistics', 'action' => 'delay', 'days' => 10], ['type' => 'financial', 'action' => 'collections_delay', 'days' => 14]], 'optimize' => true]);
        $simulation->run($run['id']);
        $data = $simulation->get($run['id']);
        $s->notes['v12'] = ['id' => $run['id'], 'status' => $data['status'], 'error' => $data['error'], 'operational_state_unchanged' => hash_equals($before, $this->operationalHash())];
    }

    private function operationalHash(): string
    {
        $ctx = hash_init('sha256');
        foreach (['products', 'warehouse_stock', 'stock_movements', 'purchase_orders', 'sales_orders', 'customer_debt_transactions', 'journal_entries', 'journal_lines', 'financial_account_transactions'] as $table) {
            hash_update($ctx, $table);
            foreach (DB::table($table)->orderBy('id')->cursor() as $row) hash_update($ctx, json_encode($row));
        }
        return hash_final($ctx);
    }
}
