<?php

namespace App\Services;

use App\Models\AccountingException;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\ShipmentContainer;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Stable read-only capability boundary for UI, automation and future intelligence.
 * It exposes concise domain summaries and never accepts raw database queries.
 */
class AimsToolRegistry
{
    public const SIMULATION_TOOLS=['create_simulation_scenario','run_strategic_simulation','get_simulation_result','compare_simulations','get_simulation_impact','run_sensitivity_analysis','get_simulation_bottlenecks','optimize_simulation_response','explain_simulation_result'];
    private function simulationAllowed():bool {return Auth::user()?->company_id&&collect(StrategicSimulationService::PERMISSIONS)->every(fn($p)=>$this->can($p));}
    public const OPTIMIZER_TOOLS=['get_supply_optimization_summary','get_optimization_plan','compare_optimization_plans','optimize_supply_plan','simulate_commitment_limit','explain_optimization_decision','stress_test_supply_plan','get_stale_optimization_plans'];
    private function optimizerAllowed():bool {return Auth::user()?->company_id&&collect(SupplyOptimizerService::PERMISSIONS)->every(fn($p)=>$this->can($p));}
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly CustomerCreditService $customerCredit,
        private readonly ReplenishmentService $replenishment,
        private readonly SupplierPerformanceService $supplierPerformance,
        private readonly ControlTowerService $controlTower,
        private readonly AccountingService $accounting,
    ) {}

    public function catalog(): array
    {
        return collect($this->definitions())->filter(fn ($tool) => $this->can($tool['required_permission'])
            && (!in_array($tool['name'],self::OPTIMIZER_TOOLS)||$this->optimizerAllowed())
            && (!in_array($tool['name'],self::SIMULATION_TOOLS)||$this->simulationAllowed())
            && (!in_array($tool['name'],['get_customer_intelligence','get_customer_activity','get_customer_reorder_opportunities','get_customer_product_affinity','get_customer_trend','get_sales_opportunities','get_at_risk_customers','get_sales_concentration'])||app(CustomerSalesIntelligenceService::class)->allowed())
            && (!in_array($tool['name'],['get_cash_forecast','get_financial_pressure_periods','get_receivable_intelligence','get_upcoming_supplier_commitments','get_inventory_capital_summary','get_financial_data_health','simulate_purchase_cash_impact'])||($this->can('finance.view')&&$this->can('financial_accounts.view'))))->values()->all();
    }

    public function execute(string $name, array $input): array
    {
        $definition = collect($this->definitions())->firstWhere('name', $name);
        abort_unless($definition, 404, 'Unknown AIMS capability.');
        abort_unless($this->can($definition['required_permission']), 403, 'You do not have permission to use this capability.');

        $result = match ($name) {
            'create_simulation_scenario','run_strategic_simulation'=>isset($input['parent_id'])?app(StrategicSimulationService::class)->derive((int)$input['parent_id'],array_diff_key($input,['parent_id'=>true])):app(StrategicSimulationService::class)->submit($input),
            'get_simulation_result'=>app(StrategicSimulationService::class)->get($this->id($input,'simulation_id')),
            'get_simulation_impact'=>app(StrategicSimulationService::class)->impact($this->id($input,'simulation_id')),
            'get_simulation_bottlenecks'=>app(StrategicSimulationService::class)->bottlenecks($this->id($input,'simulation_id')),
            'explain_simulation_result'=>app(StrategicSimulationService::class)->explain($this->id($input,'simulation_id')),
            'optimize_simulation_response'=>app(StrategicSimulationService::class)->optimize($this->id($input,'simulation_id')),
            'compare_simulations'=>app(StrategicSimulationService::class)->compare($input['ids']),
            'run_sensitivity_analysis'=>app(StrategicSimulationService::class)->sensitivity($this->id($input,'simulation_id'),array_intersect_key($input,array_flip(['assumption_index','values']))),
            'get_supply_optimization_summary'=>app(SupplyOptimizerService::class)->summary(),
            'get_stale_optimization_plans'=>app(SupplyOptimizerService::class)->summary(['stale'=>true]),
            'get_optimization_plan','compare_optimization_plans'=>app(SupplyOptimizerService::class)->get($this->id($input,'plan_id')),
            'optimize_supply_plan'=>app(SupplyOptimizerService::class)->submit($input),
            'simulate_commitment_limit'=>app(SupplyOptimizerService::class)->simulate($this->id($input,'plan_id'),['commitment_limit'=>$input['commitment_limit']]),
            'explain_optimization_decision'=>app(SupplyOptimizerService::class)->explain($this->id($input,'plan_id'),isset($input['product_id'])?$this->id($input,'product_id'):null,$input['decision_view']??'all'),
            'stress_test_supply_plan'=>app(SupplyOptimizerService::class)->stress($this->id($input,'plan_id'),array_diff_key($input,['plan_id'=>true])),
            'simulate_customer_payment_delay'=>app(AssistantScenarioService::class)->customerDelay($input),
            'simulate_shipment_delay'=>app(AssistantScenarioService::class)->shipmentDelay($input),
            'get_customer_supply_capacity'=>app(AssistantScenarioService::class)->capacity($input),
            'get_decision_changes'=>app(AssistantEvidenceService::class)->changes($input),
            'get_decision_learning_summary'=>app(DecisionLearningService::class)->summary($input),
            'get_recommendation_performance'=>app(DecisionLearningService::class)->performance($input),
            'get_decision_outcome'=>app(DecisionLearningService::class)->outcome($this->id($input,'decision_id')),
            'compare_policy_versions'=>app(DecisionLearningService::class)->comparison($this->id($input,'experiment_id')),
            'get_challenger_status'=>['rows'=>app(DecisionLearningService::class)->summary($input)['experiments']],
            'get_override_patterns'=>app(DecisionLearningService::class)->patterns($input),
            'get_policy_suggestions'=>app(DecisionLearningService::class)->suggestions(),
            'get_customer_intelligence'=>app(CustomerSalesIntelligenceService::class)->customer($this->id($input,'customer_id')),
            'get_customer_activity'=>app(CustomerSalesIntelligenceService::class)->section('cadence',$this->id($input,'customer_id')),
            'get_customer_reorder_opportunities'=>app(CustomerSalesIntelligenceService::class)->section('reorders',$this->id($input,'customer_id')),
            'get_sales_opportunities'=>app(CustomerSalesIntelligenceService::class)->section('opportunities'),
            'get_at_risk_customers'=>app(CustomerSalesIntelligenceService::class)->section('at_risk'),
            'get_customer_product_affinity'=>app(CustomerSalesIntelligenceService::class)->section('affinity',$this->id($input,'customer_id')),
            'get_sales_concentration'=>app(CustomerSalesIntelligenceService::class)->section('concentration'),
            'get_customer_trend'=>app(CustomerSalesIntelligenceService::class)->section('trend',$this->id($input,'customer_id')),
            'get_cash_forecast' => app(FinancialIntelligenceService::class)->latest((int)($input['horizon']??30)),
            'get_financial_pressure_periods' => app(FinancialIntelligenceService::class)->pressure(),
            'get_receivable_intelligence' => app(FinancialIntelligenceService::class)->receivables(isset($input['customer_id'])?$this->id($input,'customer_id'):null),
            'get_upcoming_supplier_commitments' => app(FinancialIntelligenceService::class)->section('commitments'),
            'get_inventory_capital_summary' => app(FinancialIntelligenceService::class)->section('inventory'),
            'get_financial_data_health' => app(FinancialIntelligenceService::class)->section('health'),
            'simulate_purchase_cash_impact' => app(FinancialIntelligenceScenario::class)->simulate($input),
            'get_shipment_intelligence'=>app(ShipmentIntelligenceService::class)->detail($this->id($input,'shipment_id')),
            'get_shipment_eta'=>['eta'=>app(ShipmentIntelligenceService::class)->detail($this->id($input,'shipment_id'))['eta']??null],
            'get_tracking_freshness'=>['freshness'=>app(ShipmentIntelligenceService::class)->detail($this->id($input,'shipment_id'))['tracking_freshness']??null],
            'get_shipment_inventory_impact'=>['impact'=>app(ShipmentIntelligenceService::class)->detail($this->id($input,'shipment_id'))['impact']??null],
            'get_at_risk_shipments'=>app(ShipmentIntelligenceService::class)->listing(array_replace($input,['view'=>'attention'])),
            'get_product_incoming_risk'=>app(ShipmentIntelligenceService::class)->productRisk($this->id($input,'product_id')),
            'get_route_performance'=>app(ShipmentIntelligenceService::class)->routes($input),
            'get_open_decisions'=>app(EnterpriseDecisionService::class)->listing(array_replace($input,['view'=>'attention'])),
            'get_decision','compare_decision_alternatives'=>app(EnterpriseDecisionService::class)->detail($this->id($input,'decision_id')),
            'get_product_decision'=>app(EnterpriseDecisionService::class)->listing(array_replace($input,['product_id'=>$this->id($input,'product_id'),'view'=>'all'])),
            'get_replenishment_decisions'=>app(EnterpriseDecisionService::class)->listing(array_replace($input,['type'=>'REPLENISHMENT_DECISION'])),
            'get_supplier_decisions'=>app(EnterpriseDecisionService::class)->listing(array_replace($input,['view'=>'suppliers'])),
            'get_critical_decisions'=>app(EnterpriseDecisionService::class)->listing(array_replace($input,['severity'=>'critical'])),
            'simulate_decision_change'=>app(EnterpriseDecisionService::class)->simulate($this->id($input,'decision_id'),$input),
            'get_inventory_position'=>['inventory_position'=>app(InventoryPlanningService::class)->view($this->id($input,'product_id'),$input)['plan']['inventory_position']],
            'get_reorder_recommendation'=>app(InventoryPlanningService::class)->view($this->id($input,'product_id'),$input),
            'get_supplier_options'=>['suppliers'=>app(InventoryPlanningService::class)->view($this->id($input,'product_id'),$input)['supplier_options']],
            'simulate_inventory_scenario'=>app(InventoryPlanningService::class)->scenarios($this->id($input,'product_id'),$input),
            'get_products_needing_replenishment'=>app(InventoryPlanningService::class)->listing(array_replace($input,['view'=>'replenishment'])),
            'get_inventory_plan','get_planning_assumptions'=>app(InventoryPlanningService::class)->view($this->id($input,'product_id'),$input),
            'get_planning_scenarios'=>app(InventoryPlanningService::class)->scenarios($this->id($input,'product_id'),$input),
            'get_planning_outcomes'=>['outcomes'=>app(InventoryPlanningService::class)->outcomes($this->id($input,'product_id'),false)],
            'get_supplier_lead_evidence','get_supplier_delivery_risk','get_supplier_model_performance'=>app(SupplierIntelligenceService::class)->report($this->id($input,'supplier_id'),$input),
            'get_model_performance','get_forecast_evaluations','get_forecast_drift','get_retraining_status','get_model_candidates','get_model_health','get_forecast_data_coverage'=>app(IntelligencePerformanceService::class)->report($this->id($input,'product_id'),(int)($input['horizon']??30),$input['model_version']??null),
            'get_demand_forecast','get_stockout_risk','explain_replenishment'=>app(InventoryIntelligenceService::class)->detail($this->id($input,'product_id'),(int)($input['horizon']??30)),
            'get_forecast_accuracy'=>app(InventoryIntelligenceService::class)->accuracy($this->id($input,'product_id')),
            'get_inventory_intelligence_recommendations'=>app(InventoryIntelligenceService::class)->listing($input),
            'get_product_performance'=>app(AnalyticsService::class)->productPerformance($input),
            'get_business_overview','get_sales_analytics','get_inventory_analytics','get_customer_analytics','get_order_analytics','get_logistics_analytics','get_quality_analytics','get_data_quality'=>app(AnalyticsService::class)->workspace(match($name){'get_business_overview'=>'overview','get_sales_analytics'=>'sales','get_inventory_analytics'=>'inventory','get_customer_analytics'=>'customers','get_order_analytics'=>'orders','get_logistics_analytics'=>'logistics','get_quality_analytics'=>'quality','get_data_quality'=>'data_quality'},$input),
            'get_feature_snapshot'=>app(AnalyticsDataService::class)->featureSnapshot($input),
            'get_action_center'=>app(ActionCenterService::class)->snapshot(),
            'get_my_tasks'=>app(ActionCenterService::class)->tasks(['view'=>'mine'])->toArray(),
            'get_task'=>app(ActionCenterService::class)->tasks(['task'=>$this->id($input,'task_id'),'status'=>'all'])->toArray(),
            'get_automations'=>app(AutomationService::class)->visibleRules()->latest()->paginate(50)->toArray(),
            'get_automation'=>app(AutomationService::class)->visibleRules()->findOrFail($this->id($input,'automation_id'))->toArray(),
            'get_automation_executions'=>app(AutomationService::class)->visibleExecutions()->where('automation_id',$this->id($input,'automation_id'))->latest()->paginate(30)->toArray(),
            'get_failed_automations'=>app(AutomationService::class)->visibleExecutions()->whereIn('status',['failed','blocked'])->latest()->paginate(30)->toArray(),
            'get_order','get_order_status','get_order_health','get_order_timeline','get_order_fulfillment' => app(OrderHubService::class)->detail(\App\Models\OrderIntake::findOrFail($this->id($input,'intake_id'))),
            'get_orders_needing_attention' => app(OrderHubService::class)->listing(['view'=>'attention'])->toArray(),
            'get_backorders' => app(OrderHubService::class)->listing(['view'=>'backorders'])->toArray(),
            'get_orders_by_channel' => app(OrderHubService::class)->listing(['channel_id'=>$this->id($input,'channel_id')])->toArray(),
            'get_customer_order_history' => app(OutboundService::class)->orders(['customer_id'=>$this->id($input,'customer_id')])->toArray(),
            'get_channel_performance' => app(OrderHubReportingService::class)->summary(today()->startOfMonth()->toDateString(),today()->toDateString()),
            'get_order_demand' => app(OrderHubReportingService::class)->demand($input)->toArray(),
            'get_sales_order', 'get_fulfillment_status' => app(OutboundService::class)->detail(\App\Models\SalesOrder::query()->findOrFail($this->id($input,'sales_order_id'))),
            'get_open_customer_orders' => app(OutboundService::class)->orders(['customer_id'=>$this->id($input,'customer_id')])->toArray(),
            'get_pick_task' => \App\Models\PickTask::query()->with('allocations.item.product')->findOrFail($this->id($input,'pick_task_id'))->toArray(),
            'get_warehouse_pick_queue' => \App\Models\PickTask::query()->where('warehouse_id',$this->id($input,'warehouse_id'))->whereNotIn('status',['completed','cancelled'])->orderBy('priority')->limit(50)->get()->toArray(),
            'get_dispatch_status', 'get_delivery_status' => \App\Models\OutboundDispatch::query()->with('packages.items')->findOrFail($this->id($input,'dispatch_id'))->toArray(),
            'get_customer_returns' => \App\Models\OutboundReturn::query()->whereHas('order',fn($q)=>$q->where('customer_id',$this->id($input,'customer_id')))->latest()->limit(50)->get()->toArray(),
            'get_outbound_exceptions' => \App\Models\OperationalException::query()->where('entity_type','SalesOrder')->where('status','open')->latest()->limit(50)->get()->toArray(),
            'get_document','get_document_versions','get_document_integrity_status' => app(DocumentService::class)->detail(app(DocumentService::class)->document($this->id($input,'document_id'))),
            'get_entity_documents' => $this->entityDocuments($input),
            'get_expiring_documents' => app(DocumentService::class)->listing(['expiry'=>'soon'])->toArray(),
            'get_documents_awaiting_review' => app(DocumentService::class)->listing(['status'=>'under_review'])->toArray(),
            'get_product' => $this->product($input),
            'get_inventory_status', 'get_product_availability' => $this->availability($input),
            'get_warehouse_stock' => $this->warehouseStock($input),
            'get_stock_movements' => $this->movements($input),
            'get_replenishment_recommendations' => $this->replenishment->suggestions(['product_ids' => isset($input['product_id']) ? [(int) $input['product_id']] : null])
                + ($this->can('analytics.view')&&$this->can('inventory.view')?['intelligence'=>app(InventoryIntelligenceService::class)->listing($input)]:[]),
            'get_supplier' => $this->supplier($input),
            'get_supplier_performance' => $this->supplierPerformance->scorecard(Supplier::query()->findOrFail($this->id($input, 'supplier_id'))),
            'get_purchase_order', 'get_purchase_order_status' => $this->purchaseOrder($input),
            'get_shipment', 'get_import_risk' => $this->shipment($input),
            'get_container_status' => $this->container($input),
            'get_customer' => $this->customer($input),
            'get_customer_credit_exposure', 'get_overdue_receivables' => $this->customerCredit->exposure(Customer::query()->findOrFail($this->id($input, 'customer_id'))),
            'get_financial_summary' => $this->accounting->overview($input['from'] ?? null, $input['to'] ?? null),
            'get_pending_approvals' => app(ApprovalService::class)->pendingForUser(Auth::user())->take(25)->toArray(),
            'get_accounting_exceptions' => Schema::hasTable('accounting_exceptions') ? AccountingException::query()->where('status', 'open')->latest()->limit(25)->get(['id', 'type', 'severity', 'message', 'details', 'detected_at'])->toArray() : [],
            default => throw ValidationException::withMessages(['tool' => ['This capability is not executable.']]),
        };

        Log::info('AIMS capability executed', [
            'request_id' => request()->attributes->get('request_id'), 'tool' => $name,
            'user_id' => Auth::id(), 'company_id' => Auth::user()->company_id, 'result' => 'success',
        ]);

        return ['tool' => $name, 'company_id' => (int) Auth::user()->company_id, 'data' => $result];
    }

    private function entityDocuments(array $input): array
    {
        $type = $input['entity_type'] ?? '';
        app(DocumentEntityRegistry::class)->resolve($type, $this->id($input, 'entity_id'));
        return app(DocumentService::class)->listing(['entity_type'=>$type,'entity_id'=>$input['entity_id']])->toArray();
    }

    private function product(array $input): array
    {
        $x = Product::query()->with(['category:id,name', 'supplier:id,name'])->findOrFail($this->id($input, 'product_id'));
        return ['id' => $x->id, 'name' => $x->name, 'sku' => $x->sku, 'barcode' => $x->barcode, 'unit' => $x->unit, 'lifecycle_status' => $x->lifecycle_status, 'quantity' => $x->quantity, 'available_quantity' => $x->available_quantity, 'stock_status' => $x->stock_status, 'category' => $x->category?->only(['id', 'name']), 'supplier' => $x->supplier?->only(['id', 'name'])];
    }

    private function availability(array $input): array
    {
        $x = Product::query()->with(['warehouseStock.warehouse:id,name,code', 'warehouseStock.location:id,name,code,path'])->findOrFail($this->id($input, 'product_id'));
        return ['product' => $this->product($input), 'warehouses' => $x->warehouseStock->map(fn ($row) => ['warehouse' => $row->warehouse?->only(['id', 'name', 'code']), 'location' => $row->location?->only(['id', 'name', 'code', 'path']), 'on_hand' => (float) $row->quantity, 'available' => (float) $row->available_quantity, 'reserved' => (float) $row->reserved_quantity, 'damaged' => (float) $row->damaged_quantity, 'quarantine' => (float) $row->quarantine_quantity, 'blocked' => (float) $row->blocked_quantity])->values()->all()];
    }

    private function movements(array $input): array
    {
        return StockMovement::query()->where('product_id', $this->id($input, 'product_id'))->latest('occurred_at')->limit(25)
            ->get(['id', 'type', 'movement_code', 'quantity', 'unit', 'reason', 'source_type', 'source_id', 'occurred_at'])->toArray();
    }

    private function supplier(array $input): array
    {
        $x = Supplier::query()->withCount(['products', 'catalogueItems'])->findOrFail($this->id($input, 'supplier_id'));
        return ['id' => $x->id, 'name' => $x->name, 'email' => $x->email, 'phone' => $x->phone, 'is_active' => $x->is_active, 'products_count' => $x->products_count, 'catalogue_items_count' => $x->catalogue_items_count];
    }

    private function purchaseOrder(array $input): array
    {
        $x = PurchaseOrder::query()->with(['supplier:id,name', 'warehouse:id,name,code'])->withCount(['items', 'goodsReceipts', 'shipments'])->findOrFail($this->id($input, 'purchase_order_id'));
        return ['id' => $x->id, 'po_number' => $x->po_number, 'status' => $x->status, 'supplier' => $x->supplier?->only(['id', 'name']), 'warehouse' => $x->warehouse?->only(['id', 'name', 'code']), 'total_amount' => $x->total_amount, 'currency' => $x->currency, 'payment_status' => $x->payment_status, 'remaining_balance' => $x->remaining_balance, 'ordered_at' => $x->ordered_at?->toDateString(), 'expected_at' => $x->expected_at?->toDateString(), 'items_count' => $x->items_count, 'receipts_count' => $x->goods_receipts_count, 'shipments_count' => $x->shipments_count];
    }

    private function warehouseStock(array $input): array
    {
        $warehouse=\App\Models\Warehouse::findOrFail($this->id($input,'warehouse_id'));
        return ['name'=>$warehouse->name,'address'=>$warehouse->address,'url'=>'/warehouse-operations?warehouse='.$warehouse->id,
            'rows'=>$warehouse->stock()->with('product:id,name,sku,unit')->limit(50)->get()->map(fn($s)=>['name'=>$s->product?->name,'quantity'=>$s->quantity,'available_to_promise'=>$s->available_quantity,'unit'=>$s->product?->unit,'url'=>'/products?product='.$s->product_id])->all()];
    }

    private function shipment(array $input): array
    {
        return $this->controlTower->summary(Shipment::query()->findOrFail($this->id($input, 'shipment_id')), false);
    }

    private function container(array $input): array
    {
        $x = ShipmentContainer::query()->with(['shipment:id,tracking_number,vessel_name,status,eta,position_updated_at', 'purchaseOrders:id,po_number,status'])->findOrFail($this->id($input, 'container_id'));
        return ['id' => $x->id, 'container_number' => $x->container_number, 'status' => $x->status, 'type' => $x->container_type, 'seal_number' => $x->seal_number, 'origin_port' => $x->origin_port, 'destination_port' => $x->destination_port, 'eta' => $x->eta?->toIso8601String(), 'shipment' => $x->shipment, 'purchase_orders' => $x->purchaseOrders->map->only(['id', 'po_number', 'status'])->values()->all()];
    }

    private function customer(array $input): array
    {
        $x = Customer::query()->findOrFail($this->id($input, 'customer_id'));
        return ['id' => $x->id, 'name' => $x->name, 'email' => $x->email, 'phone' => $x->phone, 'is_active' => $x->is_active, 'credit_status' => $x->credit_status, 'payment_terms_days' => $x->payment_terms_days, 'credit' => $this->customerCredit->exposure($x)];
    }

    private function id(array $input, string $key): int
    {
        $id = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $id) throw ValidationException::withMessages([$key => ['A valid positive ID is required.']]);
        return (int) $id;
    }

    private function can(string $permission): bool
    {
        return $this->permissions->roleHasPermission(Auth::user()->role, $permission);
    }

    private function definitions(): array
    {
        $id = fn (string $key) => ['type' => 'object', 'required' => [$key], 'properties' => [$key => ['type' => 'integer', 'minimum' => 1]]];
        $tool = fn ($name, $description, $input, $permission) => ['name' => $name, 'description' => $description, 'input_schema' => $input, 'output_schema' => ['type' => 'object'], 'required_permission' => $permission, 'company_scoped' => true, 'read_only' => true, 'audit_required' => false];
        return [
            ...array_map(fn($name)=>$tool($name,'Create an isolated hypothetical simulation run; no operational records or automations.',['type'=>'object','required'=>['name','horizon','assumptions'],'properties'=>['parent_id'=>['type'=>'integer','minimum'=>1],'name'=>['type'=>'string'],'description'=>['type'=>'string'],'horizon'=>['type'=>'integer','enum'=>[30,60,90,180,365]],'scope'=>['type'=>'array'],'assumptions'=>['type'=>'array'],'optimize'=>['type'=>'boolean']]],'analytics.finance'),['create_simulation_scenario','run_strategic_simulation']),
            ...array_map(fn($name)=>$tool($name,'Read or optimize isolated simulation evidence; never prepare real workflow drafts.',$id('simulation_id'),'analytics.finance'),['get_simulation_result','get_simulation_impact','get_simulation_bottlenecks','optimize_simulation_response','explain_simulation_result']),
            $tool('compare_simulations','Compare two to four frozen historical simulation runs.',['type'=>'object','required'=>['ids'],'properties'=>['ids'=>['type'=>'array']]],'analytics.finance'),
            $tool('run_sensitivity_analysis','Bounded discrete sensitivity over a frozen baseline.',['type'=>'object','required'=>['simulation_id','assumption_index','values'],'properties'=>['simulation_id'=>['type'=>'integer','minimum'=>1],'assumption_index'=>['type'=>'integer','minimum'=>0],'values'=>['type'=>'array']]],'analytics.finance'),
            ...array_map(fn($name)=>$tool($name,'Read frozen coordinated purchasing plans. No draft creation or execution.',['type'=>'object','properties'=>[]],'analytics.finance'),['get_supply_optimization_summary','get_stale_optimization_plans']),
            ...array_map(fn($name)=>$tool($name,'Read frozen solver alternatives; optimality applies only to the candidate set.',$id('plan_id'),'analytics.finance'),['get_optimization_plan','compare_optimization_plans']),
            $tool('optimize_supply_plan','Queue a local advisory optimization; no operational mutation.',['type'=>'object','required'=>['horizon'],'properties'=>['horizon'=>['type'=>'integer','enum'=>[30,60,90]],'commitment_limit'=>['type'=>'number','minimum'=>0],'product_ids'=>['type'=>'array'],'warehouse_ids'=>['type'=>'array'],'supplier_ids'=>['type'=>'array'],'category_ids'=>['type'=>'array']]],'analytics.finance'),
            $tool('simulate_commitment_limit','Queue a new scenario without overwriting the original plan.',['type'=>'object','required'=>['plan_id','commitment_limit'],'properties'=>['plan_id'=>['type'=>'integer','minimum'=>1],'commitment_limit'=>['type'=>'number','minimum'=>0]]],'analytics.finance'),
            $tool('explain_optimization_decision','Explain selected quantities and trade-offs from solver data.',['type'=>'object','required'=>['plan_id'],'properties'=>['plan_id'=>['type'=>'integer','minimum'=>1],'product_id'=>['type'=>'integer','minimum'=>1],'decision_view'=>['type'=>'string','enum'=>['all','not_purchasing']]]],'analytics.finance'),
            $tool('stress_test_supply_plan','Read-only stress test of frozen quantities and arrival assumptions.',['type'=>'object','required'=>['plan_id','alternative'],'properties'=>['plan_id'=>['type'=>'integer','minimum'=>1],'alternative'=>['type'=>'string','enum'=>['balanced','service_first','lower_commitment']],'demand_multiplier'=>['type'=>'number'],'supplier_delay'=>['type'=>'integer'],'supplier_id'=>['type'=>'integer'],'shipment_delay'=>['type'=>'integer'],'shipment_id'=>['type'=>'integer'],'collections_delay'=>['type'=>'integer']]],'analytics.finance'),
            ...array_map(fn($name)=>$tool($name,'Read-only decision-learning evidence; observed and estimated results remain separate.',['type'=>'object','properties'=>['domain'=>['type'=>'string','enum'=>DecisionLearningService::DOMAINS],'type'=>['type'=>'string']]],'analytics.view'),['get_decision_learning_summary','get_recommendation_performance','get_challenger_status','get_override_patterns','get_policy_suggestions']),
            $tool('get_decision_outcome','Frozen recommendation, separate human response and genuine observed outcome.',$id('decision_id'),'analytics.view'),
            $tool('compare_policy_versions','Frozen shadow comparison, never policy promotion.',$id('experiment_id'),'analytics.view'),
            $tool('simulate_customer_payment_delay','Hypothetical delay of one customer’s existing receivables only.',['type'=>'object','required'=>['customer_id','delay_days'],'properties'=>['customer_id'=>['type'=>'integer','minimum'=>1],'delay_days'=>['type'=>'integer','minimum'=>1]]],'analytics.finance'),
            $tool('simulate_shipment_delay','Non-mutating linked receipt and documented payment-delay scenario.',['type'=>'object','required'=>['shipment_id','delay_days'],'properties'=>['shipment_id'=>['type'=>'integer','minimum'=>1],'delay_days'=>['type'=>'integer','minimum'=>1]]],'shipments.view'),
            $tool('get_customer_supply_capacity','Read-only inventory capacity with permission-scoped customer exposure.',['type'=>'object','required'=>['product_id','customer_id','quantity'],'properties'=>['product_id'=>['type'=>'integer','minimum'=>1],'customer_id'=>['type'=>'integer','minimum'=>1],'quantity'=>['type'=>'number']]],'inventory.view'),
            $tool('get_decision_changes','Compare actual immutable decision versions; no inferred historical balances.',['type'=>'object','properties'=>['decision_id'=>['type'=>'integer','minimum'=>1],'from'=>['type'=>'string'],'to'=>['type'=>'string']]],'analytics.view'),
            ...array_map(fn($name)=>$tool($name,'Recorded customer business evidence; advisory only, never contacts customers.',$id('customer_id'),'analytics.view'),['get_customer_intelligence','get_customer_activity','get_customer_reorder_opportunities','get_customer_product_affinity','get_customer_trend']),
            ...array_map(fn($name)=>$tool($name,'Company-scoped sales signals; requires customer and sales access.',['type'=>'object'],'analytics.view'),['get_sales_opportunities','get_at_risk_customers','get_sales_concentration']),
            ...array_map(fn($name)=>$tool($name,'Frozen financial evidence. Requires finance and cash-account permissions; no posting.',['type'=>'object','properties'=>['horizon'=>['type'=>'integer','enum'=>[7,30,60,90]],'customer_id'=>['type'=>'integer','minimum'=>1]]],'analytics.finance'),['get_cash_forecast','get_financial_pressure_periods','get_receivable_intelligence','get_upcoming_supplier_commitments','get_inventory_capital_summary','get_financial_data_health']),
            $tool('simulate_purchase_cash_impact','Non-mutating additional-purchase scenario. Validates supplier constraints through V4.',['type'=>'object','required'=>['currency'],'properties'=>['currency'=>['type'=>'string'],'purchase_amount'=>['type'=>'number'],'purchase_date'=>['type'=>'string'],'product_id'=>['type'=>'integer'],'supplier_id'=>['type'=>'integer'],'base_quantity'=>['type'=>'number'],'collection_delay_days'=>['type'=>'integer'],'arrival_delay_days'=>['type'=>'integer'],'supplier_delay_days'=>['type'=>'integer'],'horizon'=>['type'=>'integer','enum'=>[7,30,60,90]],'demand_multiplier'=>['type'=>'number']]],'analytics.finance'),
            ...array_map(fn($name)=>$tool($name,'Frozen company-scoped logistics evidence; never moves stock.',$id('shipment_id'),'shipments.view'),['get_shipment_intelligence','get_shipment_eta','get_tracking_freshness','get_shipment_inventory_impact']),
            $tool('get_at_risk_shipments','Current meaningful shipment risks.',['type'=>'object'],'shipments.view'),
            $tool('get_product_incoming_risk','Shipment timing and inventory exposure.',$id('product_id'),'shipments.view'),
            $tool('get_route_performance','Genuine recorded route ranges and matured ETA outcomes.',['type'=>'object','properties'=>['origin'=>['type'=>'string'],'destination'=>['type'=>'string']]],'shipments.view'),
            ...array_map(fn($name)=>$tool($name,'Tenant-scoped advisory forecast, risk and explanation. No automatic purchasing.',$id('product_id'),'analytics.view'),['get_demand_forecast','get_stockout_risk','get_forecast_accuracy','explain_replenishment']),
            $tool('get_inventory_intelligence_recommendations','Persisted Inventory Intelligence recommendations.',['type'=>'object','properties'=>['risk'=>['type'=>'string','enum'=>['high','watch','low','unknown']],'horizon'=>['type'=>'integer','enum'=>[7,30,90]],'q'=>['type'=>'string']]],'analytics.view'),
            ...array_map(fn($name)=>$tool($name,'Read-only supplier delivery evidence, completed outcomes and procurement risk.',$id('supplier_id'),'supplier_performance.view'),['get_supplier_lead_evidence','get_supplier_delivery_risk','get_supplier_model_performance']),
            ...array_map(fn($name)=>$tool($name,'Read persisted company-scoped enterprise decisions.',['type'=>'object','properties'=>['q'=>['type'=>'string'],'warehouse_id'=>['type'=>'integer']]],'analytics.view'),['get_open_decisions','get_replenishment_decisions','get_supplier_decisions','get_critical_decisions']),
            ...array_map(fn($name)=>$tool($name,'Read decision evidence, ranked alternatives and history.',$id('decision_id'),'analytics.view'),['get_decision','compare_decision_alternatives']),
            $tool('get_product_decision','Read decisions for one product.',$id('product_id'),'analytics.view'),
            $tool('simulate_decision_change','Compare read-only V4 scenarios against a persisted decision.',['type'=>'object','required'=>['decision_id','scenarios'],'properties'=>['decision_id'=>['type'=>'integer','minimum'=>1],'scenarios'=>['type'=>'array','minItems'=>1,'maxItems'=>4,'items'=>['type'=>'object']]]],'analytics.view'),
            ...array_map(fn($name)=>$tool($name,'Read-only inventory planning and measured outcomes.',array_replace_recursive($id('product_id'),['properties'=>['warehouse_id'=>['type'=>'integer'],'supplier_id'=>['type'=>'integer'],'unit'=>['type'=>'string']]]),'analytics.view'),['get_inventory_plan','get_planning_assumptions','get_planning_outcomes','get_inventory_position','get_reorder_recommendation','get_supplier_options']),
            $tool('get_products_needing_replenishment','Current saved replenishment recommendations.',['type'=>'object','properties'=>['warehouse_id'=>['type'=>'integer'],'q'=>['type'=>'string']]],'analytics.view'),
            $tool('simulate_inventory_scenario','Non-mutating inventory scenario comparison.',['type'=>'object','required'=>['product_id','scenarios'],'properties'=>['product_id'=>['type'=>'integer'],'scenarios'=>['type'=>'array','minItems'=>1,'maxItems'=>4,'items'=>['type'=>'object']]]],'analytics.view'),
            $tool('get_planning_scenarios','Compare up to four read-only purchasing/transfer scenarios.',['type'=>'object','required'=>['product_id','scenarios'],'properties'=>['product_id'=>['type'=>'integer','minimum'=>1],'scenarios'=>['type'=>'array','minItems'=>1,'maxItems'=>4,'items'=>['type'=>'object','properties'=>['warehouse_id'=>['type'=>'integer'],'supplier_id'=>['type'=>'integer'],'base_quantity'=>['type'=>'number'],'budget'=>['type'=>'number'],'delay_days'=>['type'=>'integer'],'demand_multiplier'=>['type'=>'number'],'service_level'=>['type'=>'number'],'order_date'=>['type'=>'string'],'type'=>['enum'=>['purchase','transfer']],'source_warehouse_id'=>['type'=>'integer'],'transfer_days'=>['type'=>'integer']]]]]],'analytics.view'),
            ...array_map(fn($name)=>$tool($name,'Read-only, company-scoped real forecast outcomes and candidate review.',array_replace_recursive($id('product_id'),['properties'=>['horizon'=>['type'=>'integer','enum'=>[7,30,90]],'model_version'=>['type'=>'string']]]),'analytics.view'),['get_model_performance','get_forecast_evaluations','get_forecast_drift','get_retraining_status','get_model_candidates','get_model_health','get_forecast_data_coverage']),
            $tool('get_action_center','Authorized operational work and pending approvals.',['type'=>'object'],'tasks.view'),
            $tool('get_my_tasks','Current user operational tasks.',['type'=>'object'],'tasks.view'),
            $tool('get_task','Authorized task and recorded outcome.',$id('task_id'),'tasks.view'),
            $tool('get_automations','Company automation definitions.',['type'=>'object'],'automations.view'),
            $tool('get_automation','Versioned automation definition.',$id('automation_id'),'automations.view'),
            $tool('get_automation_executions','Execution explanations and outcomes.',$id('automation_id'),'automations.executions.view'),
            $tool('get_failed_automations','Failed and blocked execution diagnostics.',['type'=>'object'],'automations.executions.view'),
            ...array_map(fn($name)=>$tool($name,'Tenant-scoped deterministic analytics; domain permissions are rechecked.',['type'=>'object'],'analytics.view'),['get_business_overview','get_sales_analytics','get_inventory_analytics','get_product_performance','get_customer_analytics','get_order_analytics','get_logistics_analytics','get_quality_analytics','get_data_quality']),
            $tool('get_feature_snapshot','Immutable point-in-time features.',['type'=>'object'],'analytics.ml_datasets'),
            ...array_map(fn($name)=>$tool($name,'Authorized Order Hub detail, state, health and timeline.',$id('intake_id'),'fulfillment.view'),['get_order','get_order_status','get_order_health','get_order_timeline','get_order_fulfillment']),
            $tool('get_orders_needing_attention','Orders requiring human intervention.',['type'=>'object'],'fulfillment.view'),
            $tool('get_backorders','Accepted demand awaiting stock.',['type'=>'object'],'fulfillment.view'),
            $tool('get_orders_by_channel','Paginated channel orders.',$id('channel_id'),'fulfillment.view'),
            $tool('get_customer_order_history','Existing customer orders across channels.',$id('customer_id'),'fulfillment.view'),
            $tool('get_channel_performance','This month channel totals from actual orders and dispatches.',['type'=>'object'],'fulfillment.view'),
            $tool('get_order_demand','Paginated demand quantities for future analysis; no model execution.',['type'=>'object'],'fulfillment.view'),
            $tool('get_sales_order','Sales order, stock trace and fulfillment progress.',$id('sales_order_id'),'fulfillment.view'),
            $tool('get_document','Authorized document metadata.',$id('document_id'),'documents.view'),
            $tool('get_document_versions','Immutable version history.',$id('document_id'),'documents.view'),
            $tool('get_document_integrity_status','Last integrity verification.',$id('document_id'),'documents.view'),
            $tool('get_expiring_documents','Expiring documents.',['type'=>'object'],'documents.view'),
            $tool('get_documents_awaiting_review','Documents needing review.',['type'=>'object'],'documents.review'),
            $tool('get_entity_documents','Related documents.',['type'=>'object','required'=>['entity_type','entity_id'],'properties'=>['entity_type'=>['type'=>'string'],'entity_id'=>['type'=>'integer']]],'documents.view'),
            $tool('get_fulfillment_status','Current fulfillment quantities.',$id('sales_order_id'),'fulfillment.view'),
            $tool('get_open_customer_orders','Customer sales orders.',$id('customer_id'),'fulfillment.view'),
            $tool('get_pick_task','Task and allocated inventory.',$id('pick_task_id'),'fulfillment.view'),
            $tool('get_warehouse_pick_queue','Priority ordered warehouse pick queue.',$id('warehouse_id'),'fulfillment.view'),
            $tool('get_dispatch_status','Dispatch and packages.',$id('dispatch_id'),'fulfillment.view'),
            $tool('get_delivery_status','Delivery evidence and status.',$id('dispatch_id'),'fulfillment.view'),
            $tool('get_customer_returns','Customer return progress.',$id('customer_id'),'fulfillment.view'),
            $tool('get_outbound_exceptions','Actionable fulfillment exceptions.',['type'=>'object'],'fulfillment.view'),
            $tool('get_product', 'Concise product identity and stock summary.', $id('product_id'), 'inventory.view'),
            $tool('get_inventory_status', 'Product stock state by warehouse and bin.', $id('product_id'), 'inventory.view'),
            $tool('get_warehouse_stock','Recorded stock in the selected warehouse.',$id('warehouse_id'),'inventory.view'),
            $tool('get_product_availability', 'Sellable and non-sellable product quantities.', $id('product_id'), 'inventory.view'),
            $tool('get_stock_movements', 'Latest controlled product movements.', $id('product_id'), 'inventory.view'),
            $tool('get_replenishment_recommendations', 'Authoritative replenishment calculation.', ['type' => 'object', 'properties' => ['product_id' => ['type' => 'integer']]], 'replenishment.view'),
            $tool('get_supplier', 'Concise supplier profile.', $id('supplier_id'), 'supplier_catalogue.view'),
            $tool('get_supplier_performance', 'Calculated supplier scorecard.', $id('supplier_id'), 'supplier_performance.view'),
            $tool('get_purchase_order', 'Purchase order operational summary.', $id('purchase_order_id'), 'purchase_orders.view'),
            $tool('get_purchase_order_status', 'Purchase order status and linked-count summary.', $id('purchase_order_id'), 'purchase_orders.view'),
            $tool('get_shipment', 'Shipment control-tower summary.', $id('shipment_id'), 'shipments.view'),
            $tool('get_container_status', 'Container status with linked shipment and purchase orders.', $id('container_id'), 'control_tower.view'),
            $tool('get_import_risk', 'Existing import risk and milestone summary.', $id('shipment_id'), 'control_tower.view'),
            $tool('get_customer', 'Customer profile with authoritative credit summary.', $id('customer_id'), 'debts.view'),
            $tool('get_customer_credit_exposure', 'Customer exposure and aging calculation.', $id('customer_id'), 'debts.view'),
            $tool('get_overdue_receivables', 'Customer overdue aging summary.', $id('customer_id'), 'debts.view'),
            $tool('get_financial_summary', 'Financial-core summary for an optional period.', ['type' => 'object', 'properties' => ['from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date']]], 'accounting.reports.view'),
            $tool('get_pending_approvals', 'Current pending approval requests.', ['type' => 'object'], 'approvals.view'),
            $tool('get_accounting_exceptions', 'Open accounting integrity exceptions.', ['type' => 'object'], 'accounting.integrity.view'),
        ];
    }
}
