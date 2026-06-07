<?php

namespace App\Services;

use App\Events\DashboardUpdated;
use App\Events\LowStockDetected;
use App\Events\StockUpdated;
use App\Models\Product;
use App\Models\StockMovement;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockMovementService
{
    public function __construct(
        private StockMovementRepositoryInterface $movements,
        private NotificationService $notifications,
        private RedisStoreService $redisStore
    ) {}

    public function list(array $filters): \Illuminate\Database\Eloquent\Collection
    {
        return $this->movements->list($filters);
    }

    public function store(array $validated): StockMovement
    {
        $movement = DB::transaction(function () use ($validated) {
            $product = Product::lockForUpdate()->findOrFail($validated['product_id']);
            $quantityBefore = $product->quantity;

            if ($validated['type'] === 'out' && $quantityBefore < $validated['quantity']) {
                throw ValidationException::withMessages([
                    'quantity' => ['Not enough stock available for this adjustment.'],
                ]);
            }

            $quantityAfter = $validated['type'] === 'in'
                ? $quantityBefore + $validated['quantity']
                : $quantityBefore - $validated['quantity'];

            $product->update(['quantity' => $quantityAfter]);

            return $this->movements->create([
                'product_id' => $product->id,
                'type' => $validated['type'],
                'quantity' => $validated['quantity'],
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reason' => $validated['reason'] ?? null,
            ]);
        });

        $movement->load('product.category');

        $user = Auth::user();
        if ($user) {
            broadcast(new StockUpdated($user->company_id, $movement));
            broadcast(new DashboardUpdated($user->company_id));

            // Invalidate Redis dashboard stats so next request rebuilds from MySQL
            $this->redisStore->invalidateDashboardStats($user->company_id);

            // Push event into Redis activity feed (NoSQL sorted set)
            $this->redisStore->pushActivityEvent(
                $user->company_id,
                $validated['type'] === 'in' ? 'stock_in' : 'stock_out',
                'product',
                $movement->product_id,
                $user->name
            );

            if ($movement->product->quantity <= $movement->product->min_quantity) {
                $notification = $this->notifications->createLowStockAlert($movement->product);
                broadcast(new LowStockDetected($user->company_id, $notification));

                // Add to Redis low-stock set
                $this->redisStore->addLowStockAlert($user->company_id, $movement->product_id);
            } else {
                // Remove from Redis low-stock set if stock recovered
                $this->redisStore->removeLowStockAlert($user->company_id, $movement->product_id);
            }
        }

        return $movement;
    }

    public function exportCsv(array $filters): StreamedResponse
    {
        $movements = $this->movements->exportList($filters);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="stock-movements.csv"',
        ];

        $callback = function () use ($movements) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'Product', 'SKU', 'Type', 'Quantity', 'Before', 'After', 'Reason']);

            foreach ($movements as $movement) {
                fputcsv($handle, [
                    $movement->created_at->toDateTimeString(),
                    $movement->product?->name ?? '',
                    $movement->product?->sku ?? '',
                    $movement->type,
                    $movement->quantity,
                    $movement->quantity_before,
                    $movement->quantity_after,
                    $movement->reason ?? '',
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
