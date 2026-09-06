<?php

namespace App\Services;

use App\Models\OperationalException;
use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class OperationalExceptionService
{
    private const MANAGED_TYPES = [
        'departure_delayed', 'eta_moved_later', 'ais_stale', 'arrival_without_receipt',
        'receipt_without_landed_cost', 'partial_receipt_overdue', 'milestone_overdue',
        'container_capacity_exceeded',
    ];

    public function detectForShipment(Shipment $shipment): Collection
    {
        $shipment->loadMissing([
            'purchaseOrder.goodsReceipts.landedCosts',
            'purchaseOrders.goodsReceipts.landedCosts',
            'containers.items.product:id,name,weight_kg,volume_m3',
            'milestones',
        ]);
        $orders = $this->orders($shipment);
        $conditions = [];
        $arrivedAt = $shipment->arrival_date
            ?: $shipment->containers->pluck('actual_arrival')->filter()->sort()->last();

        $departure = $shipment->milestones->firstWhere('milestone_type', 'vessel_departure');
        $departureTarget = $departure?->estimated_at ?: $departure?->planned_at;
        if (! $shipment->departed_at && ! $departure?->actual_at && $departureTarget?->isPast()) {
            $conditions['departure_delayed:shipment:'.$shipment->id] = [
                'type' => 'departure_delayed', 'severity' => 'warning',
                'entity_type' => 'Shipment', 'entity_id' => $shipment->id,
                'description' => 'The planned vessel departure has passed without an actual departure.',
                'values' => ['target_departure' => $departureTarget->toIso8601String()],
                'next_action' => $this->action('Update departure', "/shipments/my-shipments?shipment={$shipment->id}"),
            ];
        }

        if (! $arrivedAt && $shipment->eta && $shipment->previous_eta && $shipment->eta->gt($shipment->previous_eta)) {
            $conditions['eta_moved_later:shipment:'.$shipment->id] = [
                'type' => 'eta_moved_later', 'severity' => 'warning',
                'entity_type' => 'Shipment', 'entity_id' => $shipment->id,
                'description' => 'The shipment ETA moved later than the previous ETA.',
                'values' => [
                    'previous_eta' => $shipment->previous_eta->toIso8601String(),
                    'current_eta' => $shipment->eta->toIso8601String(),
                    'delay_hours' => $shipment->previous_eta->diffInHours($shipment->eta),
                ],
                'next_action' => $this->action('Review shipment', "/shipments/my-shipments?shipment={$shipment->id}"),
            ];
        }

        if ($shipment->tracking_provider === 'aisstream'
            && ! $arrivedAt
            && $shipment->position_updated_at
            && $shipment->position_updated_at->lt(now()->subMinutes(30))) {
            $conditions['ais_stale:shipment:'.$shipment->id] = [
                'type' => 'ais_stale', 'severity' => 'informational',
                'entity_type' => 'Shipment', 'entity_id' => $shipment->id,
                'description' => 'The latest AIS position is stale. This is a signal-quality warning, not a shipment failure.',
                'values' => ['last_position_at' => $shipment->position_updated_at->toIso8601String()],
                'next_action' => $this->action('Open vessel tracking', "/shipments/my-shipments?shipment={$shipment->id}"),
            ];
        }

        $receipts = $orders->flatMap->goodsReceipts->unique('id')->values();
        if ($arrivedAt && $orders->isNotEmpty() && $receipts->isEmpty()) {
            $conditions['arrival_without_receipt:shipment:'.$shipment->id] = [
                'type' => 'arrival_without_receipt', 'severity' => 'critical',
                'entity_type' => 'Shipment', 'entity_id' => $shipment->id,
                'description' => 'The shipment has arrived but no linked goods receipt is recorded.',
                'values' => ['arrival_at' => $arrivedAt->toIso8601String()],
                'next_action' => $this->action('Receive goods', '/purchase-orders'),
            ];
        }

        if ($receipts->isNotEmpty() && ! $receipts->contains(
            fn ($receipt) => $receipt->landedCosts->contains('status', 'posted')
        )) {
            $conditions['receipt_without_landed_cost:shipment:'.$shipment->id] = [
                'type' => 'receipt_without_landed_cost', 'severity' => 'warning',
                'entity_type' => 'Shipment', 'entity_id' => $shipment->id,
                'description' => 'Goods were received, but no landed cost has been finalized for this import.',
                'values' => ['goods_receipt_ids' => $receipts->pluck('id')->all()],
                'next_action' => $this->action('Finalize landed cost', '/operations-center?tab=landed-costs'),
            ];
        }

        foreach ($orders as $order) {
            if ($order->status === 'partially_received' && $order->expected_at?->isPast()) {
                $conditions['partial_receipt_overdue:po:'.$order->id] = [
                    'type' => 'partial_receipt_overdue', 'severity' => 'warning',
                    'entity_type' => 'PurchaseOrder', 'entity_id' => $order->id,
                    'purchase_order_id' => $order->id,
                    'description' => "Purchase Order {$order->po_number} remains partially received after its expected date.",
                    'values' => ['expected_at' => $order->expected_at->toDateString(), 'status' => $order->status],
                    'next_action' => $this->action('Open Purchase Order', "/purchase-orders?po={$order->id}"),
                ];
            }
        }

        foreach ($shipment->milestones as $milestone) {
            $target = $milestone->estimated_at ?: $milestone->planned_at;
            if (! $milestone->actual_at && $target?->isPast()) {
                $key = 'milestone_overdue:milestone:'.$milestone->id;
                $conditions[$key] = [
                    'type' => 'milestone_overdue', 'severity' => 'warning',
                    'entity_type' => 'ShipmentMilestone', 'entity_id' => $milestone->id,
                    'description' => str_replace('_', ' ', ucfirst($milestone->milestone_type)).' is overdue.',
                    'values' => ['milestone' => $milestone->milestone_type, 'target_at' => $target->toIso8601String()],
                    'next_action' => $this->action('Update milestone', "/control-tower/{$shipment->id}"),
                ];
            }
        }

        foreach ($shipment->containers as $container) {
            $usedCbm = $this->containerTotal($container, 'unit_cbm');
            $usedWeight = $this->containerTotal($container, 'unit_weight_kg');
            $overCbm = $container->capacity_cbm !== null && $usedCbm > (float) $container->capacity_cbm + 0.0005;
            $overWeight = $container->capacity_weight_kg !== null && $usedWeight > (float) $container->capacity_weight_kg + 0.0005;
            if ($overCbm || $overWeight) {
                $conditions['container_capacity_exceeded:container:'.$container->id] = [
                    'type' => 'container_capacity_exceeded', 'severity' => 'critical',
                    'entity_type' => 'ShipmentContainer', 'entity_id' => $container->id,
                    'shipment_container_id' => $container->id,
                    'description' => "Container {$container->container_number} exceeds a configured planning capacity.",
                    'values' => [
                        'used_cbm' => $usedCbm, 'capacity_cbm' => $container->capacity_cbm,
                        'used_weight_kg' => $usedWeight, 'capacity_weight_kg' => $container->capacity_weight_kg,
                    ],
                    'next_action' => $this->action('Review container plan', "/control-tower/{$shipment->id}"),
                ];
            }
        }

        $activeKeys = array_keys($conditions);
        foreach ($conditions as $key => $condition) {
            $exception = OperationalException::withoutGlobalScopes()->firstOrNew([
                'company_id' => $shipment->company_id,
                'exception_key' => $key,
            ]);
            if (! $exception->exists || $exception->status === 'resolved') {
                $exception->detected_at = now();
            }
            $exception->fill([
                'exception_type' => $condition['type'],
                'severity' => $condition['severity'],
                'entity_type' => $condition['entity_type'],
                'entity_id' => $condition['entity_id'],
                'shipment_id' => $shipment->id,
                'shipment_container_id' => $condition['shipment_container_id'] ?? null,
                'purchase_order_id' => $condition['purchase_order_id'] ?? null,
                'status' => 'active', 'resolved_at' => null, 'resolved_by' => null,
                'description' => $condition['description'],
                'relevant_values' => $condition['values'] ?? [],
                'next_action' => $condition['next_action'] ?? null,
            ])->save();
        }

        OperationalException::withoutGlobalScopes()
            ->where('company_id', $shipment->company_id)
            ->where('shipment_id', $shipment->id)
            ->where('status', 'active')
            ->whereIn('exception_type', self::MANAGED_TYPES)
            ->when($activeKeys !== [], fn ($query) => $query->whereNotIn('exception_key', $activeKeys))
            ->when($activeKeys === [], fn ($query) => $query)
            ->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => null]);

        return OperationalException::withoutGlobalScopes()
            ->where('company_id', $shipment->company_id)
            ->where('shipment_id', $shipment->id)
            ->where('status', 'active')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->latest('detected_at')
            ->get();
    }

    public function resolve(OperationalException $exception): OperationalException
    {
        $exception->update([
            'status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => Auth::id(),
        ]);

        return $exception->fresh();
    }

    private function orders(Shipment $shipment): Collection
    {
        return $shipment->purchaseOrders
            ->when($shipment->purchaseOrder, fn (Collection $orders) => $orders->push($shipment->purchaseOrder))
            ->unique('id')->values();
    }

    private function containerTotal($container, string $field): float
    {
        return round((float) $container->items->sum(function ($item) use ($field) {
            $quantity = (float) ($item->base_quantity ?? $item->planned_quantity ?? $item->quantity);
            $unitValue = (float) ($item->{$field} ?? $item->product?->{$field === 'unit_cbm' ? 'volume_m3' : 'weight_kg'} ?? 0);

            return $quantity * $unitValue;
        }), 3);
    }

    private function action(string $label, string $url): array
    {
        return ['label' => $label, 'url' => $url];
    }
}
