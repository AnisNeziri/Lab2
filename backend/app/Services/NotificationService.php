<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;

class NotificationService
{
    public function listForUser(int $companyId, ?int $userId = null, int $limit = 50): \Illuminate\Database\Eloquent\Collection
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
