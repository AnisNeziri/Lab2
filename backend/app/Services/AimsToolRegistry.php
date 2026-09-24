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
        return collect($this->definitions())->filter(fn ($tool) => $this->can($tool['required_permission']))->values()->all();
    }

    public function execute(string $name, array $input): array
    {
        $definition = collect($this->definitions())->firstWhere('name', $name);
        abort_unless($definition, 404, 'Unknown AIMS capability.');
        abort_unless($this->can($definition['required_permission']), 403, 'You do not have permission to use this capability.');

        $result = match ($name) {
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
            'get_stock_movements' => $this->movements($input),
            'get_replenishment_recommendations' => $this->replenishment->suggestions(['product_ids' => isset($input['product_id']) ? [(int) $input['product_id']] : null]),
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
