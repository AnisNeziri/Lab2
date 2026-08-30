<?php

namespace App\Services\Tracking;

use App\Events\LowStockDetected;
use App\Jobs\SendShipmentAlertEmailJob;
use App\Models\Notification;
use App\Models\Shipment;
use App\Models\ShipmentHistory;
use App\Models\User;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ShipmentAlertService
{
    private const EVENT_MAP = [
        'vessel_tracking_started' => 'Vessel tracking started',
        'vessel_position_available' => 'Live vessel position available',
        'shipment_delayed' => 'Shipment delayed',
        'eta_changed' => 'ETA updated',
        'arrived_at_port' => 'Arrived at destination port',
        'close_to_destination' => 'Shipment approaching port',
        'delivered' => 'Shipment delivered',
    ];

    public function sync(Shipment $shipment, array $before): void
    {
        $state = $shipment->notification_state ?? [];
        $events = [];
        $alertDistance = config('tracking.alert_distance_km', 50);

        if ($shipment->status === 'delayed' && ($before['status'] ?? null) !== 'delayed') {
            $events[] = 'shipment_delayed';
        } elseif ($shipment->risk_level === 'high_risk' && ($before['risk_level'] ?? null) !== 'high_risk') {
            $events[] = 'shipment_delayed';
        } elseif ($shipment->risk_level === 'potential_delay' && ($before['risk_level'] ?? null) === 'on_schedule') {
            $events[] = 'shipment_delayed';
        }

        if ($shipment->eta && $before['eta'] && ! $shipment->eta->equalTo($before['eta'])) {
            $events[] = 'eta_changed';
        }

        if (($shipment->distance_to_port_km ?? 999) <= $alertDistance
            && ($before['distance_to_port_km'] ?? 999) > $alertDistance
            && ! in_array($shipment->status, ['delivered', 'arrived_at_port'], true)) {
            $events[] = 'close_to_destination';
        }

        if ($shipment->status === 'arrived_at_port' && ($before['status'] ?? null) !== 'arrived_at_port') {
            $events[] = 'arrived_at_port';
        }

        if ($shipment->status === 'delivered' && ($before['status'] ?? null) !== 'delivered') {
            $events[] = 'delivered';
        }

        foreach ($events as $eventType) {
            if (! empty($state[$eventType])) {
                continue;
            }

            $this->dispatch($shipment, $eventType, $before);
            $state[$eventType] = now()->toIso8601String();
        }

        $shipment->notification_state = $state;
    }

    public function logHistory(Shipment $shipment, string $eventType, string $description, array $metadata = []): void
    {
        ShipmentHistory::create([
            'company_id' => $shipment->company_id,
            'shipment_id' => $shipment->id,
            'user_id' => Auth::id(),
            'event_type' => $eventType,
            'description' => $description,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function notifyOnce(Shipment $shipment, string $eventType, array $before = []): void
    {
        if (! array_key_exists($eventType, self::EVENT_MAP)) {
            throw new \InvalidArgumentException("Unsupported shipment alert type: {$eventType}");
        }

        $state = $shipment->notification_state ?? [];
        if (! empty($state[$eventType])) {
            return;
        }

        $this->dispatch($shipment, $eventType, $before);
        $state[$eventType] = now()->toIso8601String();
        $shipment->forceFill(['notification_state' => $state])->save();
    }

    private function dispatch(Shipment $shipment, string $eventType, array $before): void
    {
        $title = self::EVENT_MAP[$eventType] ?? Str::headline($eventType);
        $message = $this->messageFor($shipment, $eventType, $before);

        $notification = Notification::create([
            'company_id' => $shipment->company_id,
            'type' => $eventType,
            'title' => $title,
            'message' => $message,
            'data' => [
                'shipment_id' => $shipment->id,
                'tracking_number' => $shipment->tracking_number,
                'vessel_name' => $shipment->vessel_name,
                'mmsi' => $shipment->mmsi,
                'imo' => $shipment->imo,
                'purchase_order_id' => $shipment->purchase_order_id,
                'destination_port' => $shipment->destination_port,
                'status' => $shipment->status,
                'eta' => optional($shipment->eta)->toIso8601String(),
                'current_location' => $shipment->last_location_label,
                'risk_level' => $shipment->risk_level,
            ],
        ]);

        $this->logHistory($shipment, $eventType, $message, $notification->data ?? []);
        SafeBroadcast::dispatch(new LowStockDetected($shipment->company_id, $notification));

        $recipients = User::where('company_id', $shipment->company_id)
            ->where('is_active', true)
            ->whereIn('role', ['admin', 'manager'])
            ->pluck('email');

        $superadmins = User::withoutGlobalScopes()
            ->where('role', 'superadmin')
            ->where('is_active', true)
            ->pluck('email');

        $recipients = $recipients->merge($superadmins)->unique();

        foreach ($recipients as $email) {
            try {
                // A missing local SMTP service must never cancel vessel
                // tracking. With the sync queue driver the mail job executes
                // inside this request, so contain transport failures here and
                // keep the in-app alert and shipment record successful.
                SendShipmentAlertEmailJob::dispatch($shipment->id, $eventType, $email);
            } catch (\Throwable $exception) {
                Log::warning('Shipment alert email could not be sent; the in-app alert was kept.', [
                    'shipment_id' => $shipment->id,
                    'event_type' => $eventType,
                    'recipient' => $email,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function messageFor(Shipment $shipment, string $eventType, array $before): string
    {
        $label = $shipment->tracking_number ?: $shipment->vessel_name ?: "Shipment #{$shipment->id}";

        return match ($eventType) {
            'vessel_tracking_started' => "{$label} is now linked to verified AIS vessel tracking.",
            'vessel_position_available' => "{$label} has received its first verified live vessel position.",
            'shipment_delayed' => "{$label} may be delayed. Current status: ".str_replace('_', ' ', $shipment->status).'.',
            'eta_changed' => "{$label} ETA changed to ".$shipment->eta?->toDayDateTimeString().'.',
            'arrived_at_port' => "{$label} has arrived at {$shipment->destination_port}.",
            'close_to_destination' => "{$label} is within {$shipment->distance_to_port_km} km of {$shipment->destination_port}.",
            'delivered' => "{$label} has been delivered to {$shipment->destination_port}.",
            default => "{$label} shipment update.",
        };
    }
}
