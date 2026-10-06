<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class NotificationService
{
    public function createAutomationNotice(int $companyId, int $userId, string $key, string $title, string $message, ?string $url): Notification
    {
        return Notification::firstOrCreate(['company_id'=>$companyId,'user_id'=>$userId,'type'=>'automation','data->automation_key'=>$key],
            ['title'=>$title,'message'=>$message,'data'=>['automation_key'=>$key,'url'=>$url ?: '/action-center']]);
    }
    public function __construct(
        private readonly InventorySnapshotService $inventorySnapshots,
        private readonly InventoryExpiryAlertService $expiryAlerts,
    ) {}

    public const SHIPMENT_TYPES = [
        'shipment_intelligence',
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
        // Keeps desktop installations accurate even when no external scheduler
        // is running; durable alert state prevents repeated notifications.
        if (Cache::add("inventory-expiry-sync:{$companyId}", true, now()->addMinutes(10))) {
            $this->expiryAlerts->syncCompany($companyId);
        }
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
        $available = $this->inventorySnapshots->forProduct($product)['available'];
        app(BusinessEventService::class)->record($available<=0?'inventory.stockout':'inventory.low_stock',$product,$product->name,
            ['available'=>$available,'minimum'=>$product->min_quantity,'shortage'=>max(0,(float)$product->min_quantity-$available)],
            'stock-threshold:'.$product->id.':'.($product->updated_at?->format('YmdHisu') ?? '').':'.$available);
        $values = [
            'company_id' => $product->company_id,
            'type' => 'low_stock',
            'title' => 'Low Stock Alert',
            'message' => "{$product->name} is running low ({$available} {$product->unit} available)",
            'data' => [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'quantity' => $available,
                'available_quantity' => $available,
            ],
        ];
        $existing = Notification::query()
            ->where('company_id', $product->company_id)
            ->where('type', 'low_stock')
            ->whereNull('read_at')
            ->where('data->product_id', $product->id)
            ->latest('id')
            ->first();
        if ($existing) {
            $existing->update($values);

            return $existing->fresh();
        }

        return Notification::create($values);
    }

    public function isLowStock(Product $product): bool
    {
        return $this->inventorySnapshots->forProduct($product)['available'] <= (float) $product->min_quantity;
    }

    public function resolveLowStockAlert(Product $product): int
    {
        return Notification::query()
            ->where('company_id', $product->company_id)
            ->where('type', 'low_stock')
            ->whereNull('read_at')
            ->where('data->product_id', $product->id)
            ->update(['read_at' => now()]);
    }
}
