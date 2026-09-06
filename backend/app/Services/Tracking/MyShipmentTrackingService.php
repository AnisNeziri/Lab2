<?php

namespace App\Services\Tracking;

use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Services\BusinessEventService;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MyShipmentTrackingService
{
    public function __construct(
        private readonly ShipmentTrackingRegistry $shipmentRegistry,
        private readonly AirCargoTrackingRegistry $airCargoRegistry,
        private readonly GlobalVesselService $vesselService,
        private readonly ShipmentRiskService $riskService,
        private readonly ShipmentAlertService $alertService,
        private readonly GeoCalculator $geo,
        private readonly VesselLookupService $vesselLookup,
        private readonly BusinessEventService $events,
    ) {}

    public function trackByNumber(array $data, int $companyId): Shipment
    {
        $trackingNumber = strtoupper(trim($data['tracking_number']));
        $transportMode = $data['transport_mode'] ?? $this->vesselService->detectTransportMode($trackingNumber);

        $snapshot = $this->lookupSnapshot($trackingNumber, $transportMode);

        if (! $snapshot) {
            throw new InvalidArgumentException('Tracking number is invalid or not recognized by the provider.');
        }

        $existing = Shipment::where('company_id', $companyId)
            ->where('tracking_number', $trackingNumber)
            ->whereNull('archived_at')
            ->first();

        if ($existing) {
            return $this->refresh($existing);
        }

        return DB::transaction(function () use ($snapshot, $data, $companyId, $trackingNumber, $transportMode) {
            $shipment = Shipment::create([
                'company_id' => $companyId,
                'tracking_number' => $trackingNumber,
                'tracking_reference' => $trackingNumber,
                'transport_mode' => $transportMode,
                'carrier' => $snapshot['carrier'] ?? null,
                'vessel_name' => $snapshot['vessel_name'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'origin_port' => $snapshot['origin_port'],
                'origin_lat' => $snapshot['origin_lat'],
                'origin_lng' => $snapshot['origin_lng'],
                'destination_port' => $snapshot['destination_port'],
                'destination_lat' => $snapshot['destination_lat'],
                'destination_lng' => $snapshot['destination_lng'],
                'status' => $snapshot['status'] ?? 'registered',
                'departed_at' => isset($snapshot['departed_at']) ? Carbon::parse($snapshot['departed_at']) : now()->subDay(),
                'eta' => isset($snapshot['eta']) ? Carbon::parse($snapshot['eta']) : null,
                'current_lat' => $snapshot['current_lat'] ?? null,
                'current_lng' => $snapshot['current_lng'] ?? null,
                'last_location_label' => $snapshot['last_location_label'] ?? null,
                'distance_to_port_km' => $snapshot['distance_to_port_km'] ?? null,
                'tracking_provider' => $snapshot['tracking_provider'] ?? config('tracking.shipment_provider'),
                'tracking_mode' => $snapshot['tracking_provider'] ?? config('tracking.shipment_provider'),
                'is_saved' => true,
                'risk_level' => 'on_schedule',
            ]);

            if (! empty($snapshot['events'])) {
                foreach ($snapshot['events'] as $event) {
                    $this->alertService->logHistory(
                        $shipment,
                        $event['status'],
                        $event['description'],
                        ['occurred_at' => $event['occurred_at'] ?? null]
                    );
                }
            }

            $this->alertService->logHistory($shipment, 'registered', 'Shipment added to tracking list.');

            $shipment = $this->refresh($shipment);
            $this->events->record('shipment.created', $shipment, $shipment->tracking_number, [
                'purchase_order_id' => $shipment->purchase_order_id, 'transport_mode' => $shipment->transport_mode,
            ], "shipment:{$shipment->id}:created");

            return $shipment;
        });
    }

    public function createAis(array $data, int $companyId): Shipment
    {
        if (! config('tracking.external_enabled')
            || config('tracking.vessel_provider') !== 'aisstream'
            || trim((string) config('tracking.aisstream.api_key')) === '') {
            throw new InvalidArgumentException('Live AIS vessel tracking is not configured on this installation.');
        }

        $vessel = $this->vesselLookup->resolveToken($data['lookup_token'], $companyId);
        $mmsi = trim((string) ($vessel['mmsi'] ?? ''));
        if (! preg_match('/^\d{9}$/', $mmsi)) {
            throw new InvalidArgumentException('The vessel lookup did not resolve a valid MMSI for live tracking.');
        }

        $purchaseOrder = ! empty($data['purchase_order_id'])
            ? PurchaseOrder::query()->with(['supplier:id,name', 'warehouse:id,name,code'])->findOrFail($data['purchase_order_id'])
            : null;

        try {
            return Cache::lock("tracking.ais.create.{$companyId}.{$mmsi}", 15)
                ->block(5, fn () => $this->createOrLinkAis($companyId, $mmsi, $vessel, $purchaseOrder));
        } catch (LockTimeoutException) {
            throw new InvalidArgumentException('This vessel is already being linked. Please try again in a few seconds.');
        }
    }

    private function createOrLinkAis(
        int $companyId,
        string $mmsi,
        array $vessel,
        ?PurchaseOrder $purchaseOrder,
    ): Shipment {
        $existing = Shipment::query()->active()->where('mmsi', $mmsi)->first();
        if ($existing) {
            if ($purchaseOrder && ! $existing->purchase_order_id) {
                $existing->update([
                    'purchase_order_id' => $purchaseOrder->id,
                    'warehouse_id' => $purchaseOrder->warehouse_id,
                    'supplier_id' => $purchaseOrder->supplier_id,
                ]);

                return $existing->fresh(['purchaseOrder', 'warehouse', 'supplier', 'histories']);
            }
            if ($purchaseOrder && (int) $existing->purchase_order_id !== (int) $purchaseOrder->id) {
                throw new InvalidArgumentException('This vessel is already active under another purchase order. Archive that tracking record before linking it again.');
            }

            return $existing->fresh(['purchaseOrder', 'warehouse', 'supplier', 'histories']);
        }

        $trackingNumber = $purchaseOrder
            ? Str::limit($purchaseOrder->po_number.' · MMSI '.$mmsi, 100, '')
            : 'MMSI-'.$mmsi;
        $destination = $this->portCoordinates($vessel['destination_port'] ?? null, $vessel['destination_code'] ?? null);
        $hasPosition = is_numeric($vessel['current_lat'] ?? null)
            && is_numeric($vessel['current_lng'] ?? null)
            && (float) $vessel['current_lat'] >= -90
            && (float) $vessel['current_lat'] <= 90
            && (float) $vessel['current_lng'] >= -180
            && (float) $vessel['current_lng'] <= 180;

        return DB::transaction(function () use ($companyId, $trackingNumber, $mmsi, $vessel, $purchaseOrder, $destination, $hasPosition) {
            $shipment = Shipment::create([
                'company_id' => $companyId,
                'tracking_number' => $trackingNumber,
                'tracking_reference' => $trackingNumber,
                'transport_mode' => 'sea',
                'carrier' => null,
                'vessel_name' => $vessel['name'] ?? null,
                'mmsi' => $mmsi,
                'imo' => $vessel['imo'] ?? null,
                'call_sign' => $vessel['call_sign'] ?? null,
                'vessel_type' => $vessel['vessel_type'] ?? null,
                'flag_country' => $vessel['flag_country'] ?? null,
                'year_built' => $vessel['year_built'] ?? null,
                'vessel_details' => $vessel['vessel_details'] ?? [],
                'details_provider' => $vessel['details_provider'] ?? 'aisstream',
                'details_updated_at' => ($vessel['details_status'] ?? null) === 'verified' ? now() : null,
                'purchase_order_id' => $purchaseOrder?->id,
                'warehouse_id' => $purchaseOrder?->warehouse_id,
                'supplier_id' => $purchaseOrder?->supplier_id,
                'origin_port' => null,
                'origin_lat' => null,
                'origin_lng' => null,
                'destination_port' => $destination['name'] ?? ($vessel['destination_port'] ?? null),
                'destination_lat' => $destination['lat'] ?? null,
                'destination_lng' => $destination['lng'] ?? null,
                'status' => $hasPosition ? 'in_transit' : 'registered',
                'departed_at' => null,
                'eta' => $vessel['eta'] ?? null,
                'current_lat' => $hasPosition ? $vessel['current_lat'] : null,
                'current_lng' => $hasPosition ? $vessel['current_lng'] : null,
                'speed_knots' => $vessel['speed_knots'] ?? null,
                'course' => $vessel['course'] ?? null,
                'heading' => $vessel['heading'] ?? null,
                'navigation_status' => $vessel['navigation_status'] ?? null,
                'position_updated_at' => $hasPosition ? ($vessel['position_updated_at'] ?? now()) : null,
                'last_refreshed_at' => $hasPosition ? now() : null,
                'last_location_label' => $hasPosition
                    ? 'Latest verified provider position'
                    : 'Waiting for the first verified AISStream position.',
                'tracking_provider' => 'aisstream',
                'tracking_mode' => 'live_ais',
                'is_saved' => true,
                'risk_level' => 'on_schedule',
            ]);

            $this->alertService->notifyOnce($shipment, 'vessel_tracking_started');
            if ($hasPosition) {
                $this->alertService->notifyOnce($shipment->fresh(), 'vessel_position_available');
            }
            $this->events->record('shipment.created', $shipment, $shipment->tracking_number, [
                'purchase_order_id' => $shipment->purchase_order_id, 'transport_mode' => 'sea', 'mmsi' => $shipment->mmsi,
            ], "shipment:{$shipment->id}:created");

            return $shipment->fresh(['purchaseOrder', 'warehouse', 'supplier', 'histories']);
        });
    }

    public function refresh(Shipment $shipment): Shipment
    {
        if (config('system.operation_mode') === 'offline') {
            return $shipment->fresh(['purchaseOrder', 'warehouse', 'supplier', 'histories']);
        }

        if ($shipment->tracking_provider === 'aisstream') {
            return $shipment->fresh(['purchaseOrder', 'warehouse', 'supplier', 'histories']);
        }

        $before = [
            'eta' => $shipment->eta?->copy(),
            'status' => $shipment->status,
            'risk_level' => $shipment->risk_level,
            'distance_to_port_km' => $shipment->distance_to_port_km,
        ];

        $provider = $this->shipmentRegistry->resolve($shipment->tracking_provider);
        $snapshot = $provider->refresh($shipment);

        if ($this->hasValidCoordinates($snapshot)) {
            $shipment->current_lat = $snapshot['current_lat'];
            $shipment->current_lng = $snapshot['current_lng'];
        }

        if (isset($snapshot['last_location_label'])) {
            $shipment->last_location_label = $snapshot['last_location_label'];
        }

        if (isset($snapshot['distance_to_port_km'])) {
            $shipment->distance_to_port_km = $snapshot['distance_to_port_km'];
        }

        if (isset($snapshot['eta'])) {
            $newEta = Carbon::parse($snapshot['eta']);
            if ($shipment->eta && ! $shipment->eta->equalTo($newEta)) {
                $shipment->previous_eta = $shipment->eta->copy();
            }
            $shipment->eta = $newEta;
        }

        if (isset($snapshot['status'])) {
            $shipment->status = $snapshot['status'];
        }

        $shipment->tracking_provider = $snapshot['tracking_provider'] ?? $provider->name();
        $shipment->tracking_mode = $shipment->tracking_provider;
        $shipment->risk_level = $this->riskService->assess($shipment);

        if ($shipment->risk_level === 'high_risk' && $shipment->status !== 'delivered') {
            $shipment->status = 'delayed';
        }

        $this->alertService->sync($shipment, $before);
        $shipment->save();

        return $shipment->fresh(['purchaseOrder', 'warehouse', 'supplier', 'histories']);
    }

    public function validateTrackingNumber(string $trackingNumber, ?string $transportMode = null): array
    {
        $transportMode = $transportMode ?? $this->vesselService->detectTransportMode($trackingNumber);
        try {
            $provider = $transportMode === 'air'
                ? $this->airCargoRegistry->resolve()
                : $this->shipmentRegistry->resolve();
        } catch (InvalidArgumentException $exception) {
            return [
                'valid' => false,
                'available' => false,
                'transport_mode' => $transportMode,
                'provider' => null,
                'message' => $exception->getMessage(),
            ];
        }

        if ($transportMode === 'air') {
            $valid = $provider->validate($trackingNumber);

            return ['valid' => $valid, 'available' => true, 'transport_mode' => 'air', 'provider' => $provider->name()];
        }

        return [
            'valid' => $provider->validate($trackingNumber),
            'available' => true,
            'transport_mode' => $transportMode,
            'provider' => $provider->name(),
        ];
    }

    private function lookupSnapshot(string $trackingNumber, string $transportMode): ?array
    {
        if ($transportMode === 'air') {
            $air = $this->airCargoRegistry->resolve();

            return $air->lookup($trackingNumber);
        }

        return $this->shipmentRegistry->resolve()->lookup($trackingNumber, $transportMode);
    }

    private function hasValidCoordinates(array $snapshot): bool
    {
        if (! isset($snapshot['current_lat'], $snapshot['current_lng'])) {
            return false;
        }

        return is_numeric($snapshot['current_lat'])
            && is_numeric($snapshot['current_lng'])
            && $snapshot['current_lat'] >= -90
            && $snapshot['current_lat'] <= 90
            && $snapshot['current_lng'] >= -180
            && $snapshot['current_lng'] <= 180;
    }

    private function portCoordinates(?string $name, ?string $code = null): ?array
    {
        $needles = collect([$name, $code])->filter()->map(fn (string $value) => $this->portKey($value));
        if ($needles->isEmpty()) {
            return null;
        }

        return collect(config('ports', []))->first(function (array $port) use ($needles) {
            $portName = $this->portKey((string) ($port['name'] ?? ''));
            $portCity = $this->portKey(explode(',', (string) ($port['name'] ?? ''), 2)[0]);
            $portCode = $this->portKey((string) ($port['code'] ?? ''));

            return $needles->contains(fn (string $needle) => $needle === $portName
                || ($portCode !== '' && $needle === $portCode)
                || ($portCity !== '' && (str_starts_with($portName, $needle) || str_starts_with($needle, $portCity))));
        });
    }

    private function portKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii(trim($value)))) ?: '';
    }
}
