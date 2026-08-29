<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

class NotificationService
{
    public const SHIPMENT_TYPES = [
        'vessel_tracking_started',
        'vessel_position_available',
        'shipment_delayed',
        'eta_changed',
        'arrived_at_port',
        'close_to_destination',
        'delivered',
    ];

    public function listForUser(int $companyId, ?int $userId = null, int $limit = 50): Collection
    {
        $query = Notification::where('company_id', $companyId)
            ->where(function ($builder) use ($userId) {
                $builder->whereNull('user_id');
                if ($userId) {
                    $builder->orWhere('user_id', $userId);
                }
            })
            ->latest()
            ->limit($limit);

        return $query->get();
    }

    public function unreadCount(int $companyId, ?int $userId = null): int
    {
        return Notification::where('company_id', $companyId)
            ->whereNull('read_at')
            ->where(function ($builder) use ($userId) {
                $builder->whereNull('user_id');
                if ($userId) {
                    $builder->orWhere('user_id', $userId);
                }
            })
            ->count();
    }

    public function markAsRead(Notification $notification): Notification
    {
        $notification->update(['read_at' => now()]);

        return $notification;
    }

    public function markAllAsRead(int $companyId, ?int $userId = null): int
    {
        return Notification::where('company_id', $companyId)
            ->whereNull('read_at')
            ->where(function ($builder) use ($userId) {
                $builder->whereNull('user_id');
                if ($userId) {
                    $builder->orWhere('user_id', $userId);
                }
            })
            ->update(['read_at' => now()]);
    }

    public function clearForUser(int $companyId, ?int $userId = null, string $scope = 'all'): int
    {
        $query = Notification::where('company_id', $companyId)
            ->where(function ($builder) use ($userId) {
                $builder->whereNull('user_id');
                if ($userId) {
                    $builder->orWhere('user_id', $userId);
                }
            });

        if ($scope === 'shipments') {
            $query->whereIn('type', self::SHIPMENT_TYPES);
        } elseif ($scope === 'notifications') {
            $query->whereNotIn('type', self::SHIPMENT_TYPES);
        }

        return $query->delete();
    }

    public function clearForShipment(int $companyId, int $shipmentId): int
    {
        return Notification::where('company_id', $companyId)
            ->where('data->shipment_id', $shipmentId)
            ->delete();
    }

    public function createLowStockAlert(Product $product): Notification
    {
        return Notification::create([
            'company_id' => $product->company_id,
            'type' => 'low_stock',
            'title' => 'Low Stock Alert',
            'message' => "{$product->name} is running low ({$product->quantity} units left)",
            'data' => [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'quantity' => $product->quantity,
            ],
        ]);
    }
}
