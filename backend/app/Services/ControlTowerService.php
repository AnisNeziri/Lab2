<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use Illuminate\Support\Collection;

class ControlTowerService
{
    public const MILESTONES = [
        'purchase_order', 'supplier_confirmation', 'supplier_production', 'cargo_ready',
        'container_booking', 'container_loaded', 'origin_port', 'vessel_departure',
        'sea_transit', 'transshipment', 'destination_port', 'customs_cleared',
        'inland_transport', 'warehouse_arrival', 'goods_receipt',
        'landed_cost_finalized', 'inventory_available',
    ];

    public function __construct(
        private readonly OperationalExceptionService $exceptions,
        private readonly BusinessEventService $events,
    ) {}

    public function shipmentQuery(array $filters)
    {
        return Shipment::query()
            ->active()
            ->with([
                'supplier:id,name', 'warehouse:id,name,code',
                'purchaseOrder.supplier:id,name', 'purchaseOrder.warehouse:id,name,code',
                'purchaseOrder.items.product:id,name,sku,unit,weight_kg,volume_m3',
                'purchaseOrder.goodsReceipts.items:id,goods_receipt_id,accepted_base_quantity',
                'purchaseOrder.goodsReceipts.landedCosts:id,goods_receipt_id,status,posted_at',
                'purchaseOrders.supplier:id,name', 'purchaseOrders.warehouse:id,name,code',
                'purchaseOrders.items.product:id,name,sku,unit,weight_kg,volume_m3',
                'purchaseOrders.goodsReceipts.items:id,goods_receipt_id,accepted_base_quantity',
                'purchaseOrders.goodsReceipts.landedCosts:id,goods_receipt_id,status,posted_at',
                'containers.items.product:id,name,sku,unit,weight_kg,volume_m3',
                'containers.purchaseOrders:id,po_number,status,total_amount,currency',
                'items.product:id,name,sku,unit,weight_kg,volume_m3',
                'items.purchaseOrderItem:id,purchase_order_id,product_id,description,unit,quantity,received_quantity',
                'milestones', 'operationalExceptions' => fn ($query) => $query->where('status', 'active'),
            ])
            ->when($filters['supplier_id'] ?? null, fn ($query, $id) => $query->where(fn ($scope) => $scope
                ->where('supplier_id', $id)
                ->orWhereHas('purchaseOrders', fn ($orders) => $orders->where('supplier_id', $id))))
            ->when($filters['purchase_order_id'] ?? null, fn ($query, $id) => $query->where(fn ($scope) => $scope
                ->where('purchase_order_id', $id)
                ->orWhereHas('purchaseOrders', fn ($orders) => $orders->whereKey($id))))
            ->when($filters['shipment_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($filters['container_id'] ?? null, fn ($query, $id) => $query->whereHas('containers', fn ($containers) => $containers->whereKey($id)))
            ->latest('id');
    }

    public function summary(Shipment $shipment, bool $detectExceptions = true): array
    {
        $shipment->loadMissing([
            'supplier:id,name', 'warehouse:id,name,code', 'purchaseOrder.supplier:id,name',
            'purchaseOrder.warehouse:id,name,code', 'purchaseOrder.items.product',
            'purchaseOrder.goodsReceipts.landedCosts', 'purchaseOrders.supplier:id,name',
            'purchaseOrders.warehouse:id,name,code', 'purchaseOrders.items.product',
            'purchaseOrders.goodsReceipts.landedCosts', 'containers.items.product',
            'containers.purchaseOrders', 'items.product', 'items.purchaseOrderItem',
            'milestones', 'operationalExceptions',
        ]);
        if ($detectExceptions) {
            $shipment->setRelation('operationalExceptions', $this->exceptions->detectForShipment($shipment));
        }

        $orders = $this->orders($shipment);
        $timeline = $this->timeline($shipment, $orders);
        $currentIndex = collect($timeline)->search(fn ($step) => $step['state'] === 'current');
        if ($currentIndex === false) {
            $currentIndex = collect($timeline)->search(fn ($step) => $step['state'] !== 'completed');
        }
        $current = $currentIndex === false ? collect($timeline)->last(fn ($step) => $step['state'] === 'completed') : $timeline[$currentIndex];
        $previous = $currentIndex === false || $currentIndex === 0 ? null : $timeline[$currentIndex - 1];
        $next = $currentIndex === false ? null : ($timeline[$currentIndex + 1] ?? null);
        $receipts = $orders->flatMap->goodsReceipts->unique('id')->values();
        $landedCosts = $receipts->flatMap->landedCosts->unique('id')->values();
        $latestPosition = $shipment->position_updated_at;
        $aisFreshness = $shipment->tracking_provider !== 'aisstream'
            ? 'not_applicable'
            : (! $latestPosition ? 'no_signal' : ($latestPosition->gte(now()->subMinutes(10)) ? 'live' : 'stale'));

        return [
            'id' => $shipment->id,
            'reference' => $shipment->tracking_number ?: $shipment->tracking_reference ?: '#'.$shipment->id,
            'supplier' => $shipment->supplier ?: $orders->first()?->supplier,
            'purchase_orders' => $orders->map(fn ($order) => [
                'id' => $order->id, 'po_number' => $order->po_number, 'status' => $order->status,
                'expected_at' => $order->expected_at?->toDateString(), 'supplier' => $order->supplier,
                'url' => "/purchase-orders?po={$order->id}",
            ])->values(),
            'products' => $this->products($shipment, $orders),
            'containers' => $shipment->containers->map(fn ($container) => $this->containerSummary($container))->values(),
            'vessel' => [
                'name' => $shipment->vessel_name, 'mmsi' => $shipment->mmsi, 'imo' => $shipment->imo,
                'position_updated_at' => $latestPosition?->toIso8601String(), 'ais_freshness' => $aisFreshness,
            ],
            'origin' => $shipment->origin_port,
            'destination' => $shipment->destination_port,
            'etd' => $shipment->departed_at?->toIso8601String(),
            'eta' => $shipment->eta?->toIso8601String(),
            'actual_departure' => $shipment->departed_at?->toIso8601String(),
            'actual_arrival' => $shipment->arrival_date?->toIso8601String(),
            'delay_hours' => $this->delayHours($shipment),
            'current_milestone' => $current,
            'previous_milestone' => $previous,
            'next_milestone' => $next,
            'timeline' => $timeline,
            'missing_milestones' => collect($timeline)->where('state', 'unknown')->pluck('type')->values(),
            'goods_receipt' => [
                'status' => $receipts->isEmpty() ? 'not_received' : ($orders->every(fn ($order) => $order->status === 'received') ? 'received' : 'partial'),
                'count' => $receipts->count(), 'latest_at' => $receipts->max('received_at')?->toIso8601String(),
            ],
            'landed_cost' => [
                'status' => $landedCosts->contains('status', 'posted') ? 'finalized' : ($landedCosts->isEmpty() ? 'not_started' : 'draft'),
                'count' => $landedCosts->count(),
            ],
            'warehouse' => $shipment->warehouse ?: $orders->first()?->warehouse,
            'exceptions' => $shipment->operationalExceptions->values(),
            'next_action' => $this->nextAction($shipment, $orders, $receipts, $landedCosts),
            'links' => [
                'shipment' => "/shipments/my-shipments?shipment={$shipment->id}",
                'supplier' => ($shipment->supplier ?: $orders->first()?->supplier)
                    ? '/suppliers' : null,
                'warehouse' => ($shipment->warehouse ?: $orders->first()?->warehouse)
                    ? '/warehouse-operations' : null,
            ],
        ];
    }

    public function upsertMilestone(Shipment $shipment, array $data): ShipmentMilestone
    {
        $containerId = $data['shipment_container_id'] ?? null;
        if ($containerId && ! $shipment->containers()->whereKey($containerId)->exists()) {
            abort(404);
        }
        $scopeKey = $containerId ? 'container:'.$containerId : 'shipment';
        $milestone = ShipmentMilestone::updateOrCreate(
            [
                'company_id' => $shipment->company_id, 'shipment_id' => $shipment->id,
                'scope_key' => $scopeKey, 'milestone_type' => $data['milestone_type'],
            ],
            [
                'shipment_container_id' => $containerId,
                'status' => $data['actual_at'] ? 'completed' : ($data['status'] ?? 'planned'),
                'planned_at' => $data['planned_at'] ?? null,
                'estimated_at' => $data['estimated_at'] ?? null,
                'actual_at' => $data['actual_at'] ?? null,
                'source' => 'manual', 'notes' => $data['notes'] ?? null,
                'updated_by' => auth()->id(),
            ],
        );
        $this->exceptions->detectForShipment($shipment->fresh());
        $eventType = match ($data['milestone_type']) {
            'container_loaded' => 'container.loaded',
            'vessel_departure' => 'shipment.departed',
            'destination_port', 'warehouse_arrival' => 'shipment.arrived',
            'customs_cleared' => 'container.customs_cleared',
            default => null,
        };
        if ($eventType && $milestone->actual_at) {
            $this->events->record($eventType, $shipment, $shipment->tracking_number, [
                'milestone_id' => $milestone->id, 'milestone_type' => $milestone->milestone_type,
                'actual_at' => $milestone->actual_at->toIso8601String(),
                'shipment_container_id' => $milestone->shipment_container_id,
            ], "milestone:{$milestone->id}:{$eventType}:{$milestone->actual_at->timestamp}");
        }

        return $milestone->fresh(['updater:id,name']);
    }

    public function planContainer(array $data): array
    {
        $profile = collect($this->containerProfiles())->firstWhere('code', $data['container_type']) ?? [];
        $capacityCbm = (float) ($data['capacity_cbm'] ?? $profile['capacity_cbm'] ?? 0);
        $capacityWeight = (float) ($data['capacity_weight_kg'] ?? $profile['capacity_weight_kg'] ?? 0);
        $lines = collect($data['items'])->map(function (array $input): array {
            $item = \App\Models\PurchaseOrderItem::query()->with('product')->findOrFail($input['purchase_order_item_id']);
            $quantity = round((float) $input['quantity'], 3);
            $factor = $item->conversion_mode === 'fixed' ? (float) ($item->conversion_factor ?: 1) : 1;
            $baseQuantity = round((float) ($input['base_quantity'] ?? $quantity * $factor), 3);
            $unitCbm = (float) ($input['unit_cbm'] ?? $item->product?->volume_m3 ?? 0);
            $unitWeight = (float) ($input['unit_weight_kg'] ?? $item->product?->weight_kg ?? 0);

            return [
                'purchase_order_item_id' => $item->id, 'product' => $item->product?->name ?: $item->description,
                'quantity' => $quantity, 'base_quantity' => $baseQuantity,
                'cbm' => round($baseQuantity * $unitCbm, 3),
                'weight_kg' => round($baseQuantity * $unitWeight, 3),
            ];
        });
        $totalCbm = round((float) $lines->sum('cbm'), 3);
        $totalWeight = round((float) $lines->sum('weight_kg'), 3);

        return [
            'container_type' => $data['container_type'], 'total_quantity' => round((float) $lines->sum('quantity'), 3),
            'total_cbm' => $totalCbm, 'total_weight_kg' => $totalWeight,
            'capacity_cbm' => $capacityCbm ?: null, 'capacity_weight_kg' => $capacityWeight ?: null,
            'used_cbm_percent' => $capacityCbm > 0 ? round($totalCbm / $capacityCbm * 100, 1) : null,
            'used_weight_percent' => $capacityWeight > 0 ? round($totalWeight / $capacityWeight * 100, 1) : null,
            'remaining_cbm' => $capacityCbm > 0 ? round($capacityCbm - $totalCbm, 3) : null,
            'remaining_weight_kg' => $capacityWeight > 0 ? round($capacityWeight - $totalWeight, 3) : null,
            'warnings' => array_values(array_filter([
                $capacityCbm > 0 && $totalCbm > $capacityCbm ? 'CBM capacity exceeded.' : null,
                $capacityWeight > 0 && $totalWeight > $capacityWeight ? 'Weight capacity exceeded.' : null,
            ])),
            'items' => $lines->values(),
        ];
    }

    public function containerProfiles(): array
    {
        return [
            ['code' => '20GP', 'label' => '20 ft General Purpose', 'capacity_cbm' => 33.2, 'capacity_weight_kg' => 28200],
            ['code' => '40GP', 'label' => '40 ft General Purpose', 'capacity_cbm' => 67.7, 'capacity_weight_kg' => 26700],
            ['code' => '40HQ', 'label' => '40 ft High Cube', 'capacity_cbm' => 76.3, 'capacity_weight_kg' => 26500],
        ];
    }

    private function timeline(Shipment $shipment, Collection $orders): array
    {
        $explicit = $shipment->milestones->keyBy('milestone_type');
        $receipts = $orders->flatMap->goodsReceipts->unique('id')->values();
        $landed = $receipts->flatMap->landedCosts->where('status', 'posted')->values();
        $container = $shipment->containers->first();
        $arrival = $shipment->arrival_date ?: $shipment->containers->pluck('actual_arrival')->filter()->sort()->last();
        $derived = [
            'purchase_order' => ['actual' => $orders->pluck('ordered_at')->filter()->sort()->first()],
            'supplier_confirmation' => ['completed' => $orders->contains(fn ($order) => in_array($order->status, ['confirmed', 'ordered', 'partially_received', 'received', 'completed'], true))],
            'container_booking' => ['completed' => $shipment->containers->contains(fn ($row) => filled($row->booking_reference))],
            'container_loaded' => ['actual' => $shipment->containers->pluck('actual_departure')->filter()->sort()->first(), 'completed' => $shipment->containers->contains(fn ($row) => in_array($row->status, ['loaded', 'departed', 'in_transit', 'arrived'], true))],
            'origin_port' => ['completed' => filled($shipment->origin_port)],
            'vessel_departure' => ['actual' => $shipment->departed_at ?: $container?->actual_departure],
            'sea_transit' => ['completed' => (bool) $arrival, 'current' => (bool) $shipment->departed_at && ! $arrival],
            'transshipment' => ['completed' => filled($shipment->transshipment_port)],
            'destination_port' => ['actual' => $arrival],
            'goods_receipt' => ['actual' => $receipts->pluck('received_at')->filter()->sort()->last()],
            'landed_cost_finalized' => ['actual' => $landed->pluck('posted_at')->filter()->sort()->last()],
            'inventory_available' => ['actual' => $receipts->pluck('received_at')->filter()->sort()->last()],
        ];

        $steps = collect(self::MILESTONES)->map(function (string $type) use ($explicit, $derived): array {
            $record = $explicit->get($type);
            $actual = $record?->actual_at ?: ($derived[$type]['actual'] ?? null);
            $planned = $record?->planned_at;
            $estimated = $record?->estimated_at;
            $completed = (bool) $actual || ($derived[$type]['completed'] ?? false);
            $target = $estimated ?: $planned;
            $state = $completed ? 'completed' : (($target && $target->isPast()) ? 'delayed' : ($target ? 'planned' : 'unknown'));
            if (($derived[$type]['current'] ?? false) && ! $completed) {
                $state = 'current';
            }

            return [
                'type' => $type, 'state' => $state,
                'planned_at' => $planned?->toIso8601String(),
                'estimated_at' => $estimated?->toIso8601String(),
                'actual_at' => $actual?->toIso8601String(),
                'notes' => $record?->notes, 'source' => $record?->source ?: ($completed ? 'existing_aims_data' : null),
            ];
        })->all();

        if (! collect($steps)->contains(fn ($step) => $step['state'] === 'current')) {
            $lastCompleted = collect($steps)->search(fn ($step) => $step['state'] !== 'completed');
            if ($lastCompleted !== false && $steps[$lastCompleted]['state'] === 'unknown') {
                $steps[$lastCompleted]['state'] = 'current';
            }
        }

        return $steps;
    }

    private function orders(Shipment $shipment): Collection
    {
        return $shipment->purchaseOrders
            ->when($shipment->purchaseOrder, fn (Collection $orders) => $orders->push($shipment->purchaseOrder))
            ->unique('id')->values();
    }

    private function products(Shipment $shipment, Collection $orders): Collection
    {
        if ($shipment->items->isNotEmpty()) {
            return $shipment->items->map(fn ($item) => [
                'id' => $item->product_id, 'name' => $item->product?->name ?: $item->description,
                'quantity' => $item->planned_quantity ?: $item->quantity, 'loaded_quantity' => $item->loaded_quantity,
                'unit' => $item->unit,
            ])->values();
        }

        return $orders->flatMap->items->map(fn ($item) => [
            'id' => $item->product_id, 'name' => $item->product?->name ?: $item->description,
            'quantity' => $item->quantity, 'loaded_quantity' => null, 'unit' => $item->unit,
        ])->values();
    }

    private function containerSummary($container): array
    {
        $usedCbm = round((float) $container->items->sum(fn ($item) => (float) ($item->base_quantity ?? $item->quantity) * (float) ($item->unit_cbm ?? $item->product?->volume_m3 ?? 0)), 3);
        $usedWeight = round((float) $container->items->sum(fn ($item) => (float) ($item->base_quantity ?? $item->quantity) * (float) ($item->unit_weight_kg ?? $item->product?->weight_kg ?? 0)), 3);

        return [
            ...$container->only([
                'id', 'container_number', 'seal_number', 'container_type', 'booking_reference',
                'bill_of_lading', 'forwarder', 'vessel_name', 'voyage', 'origin_port',
                'destination_port', 'etd', 'eta', 'actual_departure', 'actual_arrival',
                'status', 'capacity_cbm', 'capacity_weight_kg',
            ]),
            'purchase_orders' => $container->purchaseOrders->map->only(['id', 'po_number'])->values(),
            'used_cbm' => $usedCbm, 'used_weight_kg' => $usedWeight,
            'remaining_cbm' => $container->capacity_cbm === null ? null : round((float) $container->capacity_cbm - $usedCbm, 3),
            'remaining_weight_kg' => $container->capacity_weight_kg === null ? null : round((float) $container->capacity_weight_kg - $usedWeight, 3),
            'capacity_warning' => ($container->capacity_cbm !== null && $usedCbm > (float) $container->capacity_cbm)
                || ($container->capacity_weight_kg !== null && $usedWeight > (float) $container->capacity_weight_kg),
        ];
    }

    private function delayHours(Shipment $shipment): ?int
    {
        if (! $shipment->eta || ! $shipment->previous_eta || ! $shipment->eta->gt($shipment->previous_eta)) {
            return null;
        }

        return $shipment->previous_eta->diffInHours($shipment->eta);
    }

    private function nextAction(Shipment $shipment, Collection $orders, Collection $receipts, Collection $landed): ?array
    {
        if ($orders->isEmpty()) return ['label' => 'Link a Purchase Order', 'url' => "/shipments/my-shipments?shipment={$shipment->id}"];
        if ($shipment->containers->isEmpty()) return ['label' => 'Create or assign a container', 'url' => "/shipments/my-shipments?shipment={$shipment->id}"];
        if (! $shipment->departed_at && ! $shipment->containers->contains(fn ($row) => $row->actual_departure)) return ['label' => 'Update departure', 'url' => "/control-tower/{$shipment->id}"];
        if (($shipment->arrival_date || $shipment->containers->contains(fn ($row) => $row->actual_arrival)) && $receipts->isEmpty()) return ['label' => 'Receive goods', 'url' => '/purchase-orders'];
        if ($receipts->isNotEmpty() && ! $landed->contains('status', 'posted')) return ['label' => 'Finalize landed cost', 'url' => '/operations-center?tab=landed-costs'];

        return null;
    }
}
