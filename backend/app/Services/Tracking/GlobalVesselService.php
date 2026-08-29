<?php

namespace App\Services\Tracking;

use App\Models\Shipment;

class GlobalVesselService
{
    public function __construct(
        private readonly AirCargoTrackingRegistry $airCargoRegistry,
    ) {}

    public function vessels(array $filters = []): array
    {
        $query = Shipment::query()->active()->where('tracking_provider', 'aisstream')
            ->with('purchaseOrder:id,po_number');

        if (! empty($filters['origin'])) {
            $query->where('origin_port', $filters['origin']);
        }
        if (! empty($filters['destination'])) {
            $query->where('destination_port', $filters['destination']);
        }
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q
                ->where('vessel_name', 'like', $search)
                ->orWhere('mmsi', 'like', $search)
                ->orWhere('imo', 'like', $search)
                ->orWhere('tracking_number', 'like', $search)
                ->orWhereHas('purchaseOrder', fn ($po) => $po->where('po_number', 'like', $search)));
        }

        return $query->get()
            ->map(function (Shipment $shipment) {
                $positionState = $this->positionState($shipment);
                $aisDetails = (array) data_get($shipment->vessel_details, 'aisstream', []);

                return [
                    'id' => (string) $shipment->id,
                    'name' => $shipment->vessel_name ?: "MMSI {$shipment->mmsi}",
                    'mmsi' => $shipment->mmsi,
                    'imo' => $shipment->imo,
                    'call_sign' => $shipment->call_sign,
                    'vessel_type' => $shipment->vessel_type,
                    'flag_country' => $shipment->flag_country,
                    'purchase_order_id' => $shipment->purchase_order_id,
                    'purchase_order_number' => $shipment->purchaseOrder?->po_number,
                    'origin_port' => $shipment->origin_port,
                    'origin_lat' => $shipment->origin_lat,
                    'origin_lng' => $shipment->origin_lng,
                    'destination_port' => $shipment->destination_port,
                    'destination_lat' => $shipment->destination_lat,
                    'destination_lng' => $shipment->destination_lng,
                    'current_lat' => $shipment->current_lat,
                    'current_lng' => $shipment->current_lng,
                    'speed_knots' => $shipment->speed_knots,
                    'course' => $shipment->course,
                    'heading' => $shipment->heading,
                    'navigation_status' => $shipment->navigation_status,
                    'navigation_status_label' => $this->navigationStatusLabel($shipment->navigation_status),
                    'status' => $shipment->status,
                    'eta' => $shipment->eta?->toIso8601String(),
                    'distance_to_port_km' => $shipment->distance_to_port_km,
                    'position_updated_at' => $shipment->position_updated_at?->toIso8601String(),
                    'ais_last_update_at' => $shipment->last_refreshed_at?->toIso8601String(),
                    'position_state' => $positionState,
                    'has_position' => $this->validCoordinates($shipment->current_lat, $shipment->current_lng),
                    'has_live_position' => $positionState === 'live',
                    'provider' => 'aisstream',
                    'location_label' => $shipment->last_location_label ?: 'Waiting for the first AISStream position broadcast',
                    'static_data' => (array) ($aisDetails['static'] ?? []),
                    'voyage_data' => (array) ($aisDetails['voyage'] ?? []),
                ];
            })->values()->all();
    }

    public function vessel(string $id): ?array
    {
        return collect($this->vessels())->firstWhere('id', $id);
    }

    public function metrics(array $filters = []): array
    {
        $fleet = $this->vessels($filters);
        $origins = array_values(array_unique(array_filter(array_map(fn ($v) => $v['origin_port'], $fleet))));
        $destinations = array_values(array_unique(array_filter(array_map(fn ($v) => $v['destination_port'], $fleet))));
        $active = array_filter($fleet, fn ($v) => ! in_array($v['status'], ['delivered', 'arrived_at_port'], true));
        $linkedOrders = array_values(array_unique(array_filter(array_map(fn ($v) => $v['purchase_order_id'], $fleet))));

        return [
            'total_vessels' => count($fleet),
            'active_vessels' => count($active),
            'linked_purchase_orders' => count($linkedOrders),
            'origin_ports' => $origins,
            'destination_ports' => $destinations,
        ];
    }

    public function ports(): array
    {
        return config('ports');
    }

    public function detectTransportMode(string $trackingNumber): string
    {
        try {
            if ($this->airCargoRegistry->resolve()->supports($trackingNumber)) {
                return 'air';
            }
        } catch (\InvalidArgumentException) {
            if (preg_match('/^(AIR-|AWB|\d{3}-?\d{8})/i', trim($trackingNumber))) {
                return 'air';
            }
        }

        return 'sea';
    }

    private function validCoordinates(mixed $lat, mixed $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    private function positionState(Shipment $shipment): string
    {
        if (! $this->validCoordinates($shipment->current_lat, $shipment->current_lng)) {
            return 'waiting_for_data';
        }
        if (! $shipment->position_updated_at) {
            return 'last_known';
        }

        $liveMinutes = max(1, (int) config('tracking.live_position_minutes', 10));
        $coverageHours = max(1, (int) config('tracking.outside_coverage_hours', 24));
        if ($shipment->position_updated_at->gte(now()->subMinutes($liveMinutes))) {
            return 'live';
        }
        if ($shipment->position_updated_at->gte(now()->subHours($coverageHours))) {
            return 'last_known';
        }

        return 'outside_coverage';
    }

    private function navigationStatusLabel(?int $status): ?string
    {
        return [
            0 => 'Under way using engine',
            1 => 'At anchor',
            2 => 'Not under command',
            3 => 'Restricted manoeuvrability',
            4 => 'Constrained by draught',
            5 => 'Moored',
            6 => 'Aground',
            7 => 'Engaged in fishing',
            8 => 'Under way sailing',
            9 => 'Reserved for HSC',
            10 => 'Reserved for WIG',
            11 => 'Power-driven vessel towing astern',
            12 => 'Power-driven vessel pushing or towing alongside',
            13 => 'Reserved',
            14 => 'AIS-SART / active distress',
            15 => 'Not defined',
        ][$status] ?? null;
    }
}
