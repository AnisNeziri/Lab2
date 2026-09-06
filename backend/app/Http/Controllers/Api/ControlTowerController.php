<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\OperationalException;
use App\Models\Shipment;
use App\Services\ControlTowerService;
use App\Services\OperationalExceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ControlTowerController extends Controller
{
    public function __construct(
        private readonly ControlTowerService $tower,
        private readonly OperationalExceptionService $exceptions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'purchase_order_id' => ['nullable', 'integer'],
            'shipment_id' => ['nullable', 'integer'],
            'container_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->tower->shipmentQuery($filters)->paginate($filters['per_page'] ?? 20);
        $page->setCollection($page->getCollection()->map(fn (Shipment $shipment) => $this->tower->summary($shipment)));

        return response()->json($page);
    }

    public function show(Shipment $shipment): JsonResponse
    {
        return response()->json($this->tower->summary($shipment));
    }

    public function milestone(Request $request, Shipment $shipment): JsonResponse
    {
        $validated = $request->validate([
            'shipment_container_id' => ['nullable', 'integer'],
            'milestone_type' => ['required', Rule::in(ControlTowerService::MILESTONES)],
            'status' => ['nullable', Rule::in(['planned', 'current', 'completed'])],
            'planned_at' => ['nullable', 'date'],
            'estimated_at' => ['nullable', 'date'],
            'actual_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $before = $shipment->milestones()->where('milestone_type', $validated['milestone_type'])->first()?->toArray();
        $milestone = $this->tower->upsertMilestone($shipment, $validated);
        ActivityLog::create([
            'company_id' => $shipment->company_id, 'user_id' => $request->user()->id,
            'action' => 'shipment.milestone.updated', 'entity' => 'ShipmentMilestone',
            'entity_id' => $milestone->id, 'description' => 'Shipment milestone updated.',
            'old_value' => $before, 'new_value' => $milestone->only(['milestone_type', 'status', 'planned_at', 'estimated_at', 'actual_at', 'notes']),
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['milestone' => $milestone, 'shipment' => $this->tower->summary($shipment->fresh())]);
    }

    public function refreshExceptions(Shipment $shipment): JsonResponse
    {
        return response()->json(['exceptions' => $this->exceptions->detectForShipment($shipment)]);
    }

    public function attention(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'supplier_id' => ['nullable', 'integer'], 'purchase_order_id' => ['nullable', 'integer'],
            'shipment_id' => ['nullable', 'integer'], 'container_id' => ['nullable', 'integer'],
            'exception_type' => ['nullable', 'string', 'max:80'],
            'severity' => ['nullable', Rule::in(['critical', 'warning', 'informational'])],
            'status' => ['nullable', Rule::in(['active', 'resolved'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        Shipment::query()->active()
            ->when($filters['shipment_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->chunkById(50, function ($shipments): void {
                foreach ($shipments as $shipment) {
                    $this->exceptions->detectForShipment($shipment);
                }
            });

        $query = OperationalException::query()->with(['shipment:id,tracking_number,tracking_reference,supplier_id', 'shipment.supplier:id,name', 'container:id,container_number', 'purchaseOrder:id,po_number']);
        $query->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->whereHas('shipment', fn ($s) => $s
                ->where('supplier_id', $id)
                ->orWhereHas('purchaseOrders', fn ($orders) => $orders->where('supplier_id', $id))))
            ->when($filters['purchase_order_id'] ?? null, fn ($q, $id) => $q->where(fn ($scope) => $scope
                ->where('purchase_order_id', $id)
                ->orWhereHas('shipment', fn ($shipment) => $shipment->where('purchase_order_id', $id))
                ->orWhereHas('shipment.purchaseOrders', fn ($orders) => $orders->whereKey($id))))
            ->when($filters['shipment_id'] ?? null, fn ($q, $id) => $q->where('shipment_id', $id))
            ->when($filters['container_id'] ?? null, fn ($q, $id) => $q->where('shipment_container_id', $id))
            ->when($filters['exception_type'] ?? null, fn ($q, $value) => $q->where('exception_type', $value))
            ->when($filters['severity'] ?? null, fn ($q, $value) => $q->where('severity', $value))
            ->where('status', $filters['status'] ?? 'active')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->latest('detected_at');

        return response()->json($query->paginate($filters['per_page'] ?? 30));
    }

    public function resolve(Request $request, OperationalException $operationalException): JsonResponse
    {
        $before = $operationalException->toArray();
        $resolved = $this->exceptions->resolve($operationalException);
        ActivityLog::create([
            'company_id' => $resolved->company_id, 'user_id' => $request->user()->id,
            'action' => 'operational_exception.resolved', 'entity' => 'OperationalException',
            'entity_id' => $resolved->id, 'description' => 'Operational exception manually resolved.',
            'old_value' => $before, 'new_value' => $resolved->only(['status', 'resolved_at', 'resolved_by']),
            'ip_address' => $request->ip(),
        ]);

        return response()->json($resolved);
    }

    public function profiles(): JsonResponse
    {
        return response()->json(['data' => $this->tower->containerProfiles()]);
    }

    public function plan(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate([
            'container_type' => ['required', 'string', 'max:30'],
            'capacity_cbm' => ['nullable', 'numeric', 'min:0.001'],
            'capacity_weight_kg' => ['nullable', 'numeric', 'min:0.001'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.purchase_order_item_id' => ['required', 'integer', Rule::exists('purchase_order_items', 'id')->where(fn ($q) => $q->whereIn('purchase_order_id', \App\Models\PurchaseOrder::query()->where('company_id', $companyId)->select('id')))],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.base_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'items.*.unit_cbm' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($this->tower->planContainer($validated));
    }
}
