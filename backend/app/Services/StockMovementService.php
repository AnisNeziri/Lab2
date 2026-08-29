<?php

namespace App\Services;

use App\Events\DashboardUpdated;
use App\Events\LowStockDetected;
use App\Events\StockUpdated;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\InventoryLot;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use App\Support\SafeBroadcast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockMovementService
{
    public const MOVEMENT_CODES = [
        'opening_balance',
        'manual_adjustment_in',
        'manual_adjustment_out',
        'import_adjustment_in',
        'import_adjustment_out',
        'purchase_receipt',
        'daily_sale',
        'daily_sale_reversal',
        'invoice_sale',
        'invoice_credit',
        'reconciliation_adjustment',
        'legacy_stock_in',
        'legacy_stock_out',
        'customer_return',
        'supplier_return',
        'transfer_out',
        'transfer_in',
        'transfer_return',
        'bin_transfer_out',
        'bin_transfer_in',
        'damage_received',
        'damage_writeoff',
        'sample',
        'internal_use',
        'warehouse_pick',
        'stock_count',
        'reservation',
        'reservation_release',
        'quarantine_in',
        'quarantine_release',
    ];

    public function __construct(
        private StockMovementRepositoryInterface $movements,
        private NotificationService $notifications,
        private RedisStoreService $redisStore,
        private WarehouseInventoryService $warehouseInventory,
        private UnitConversionService $unitConversions,
        private InventoryCostingService $costing,
        private TraceabilityService $traceability,
    ) {}

    public function list(array $filters): Collection
    {
        return $this->movements->list($filters);
    }

    public function store(array $validated): StockMovement
    {
        $idempotencyKey = filled($validated['idempotency_key'] ?? null)
            ? trim((string) $validated['idempotency_key'])
            : null;

        try {
            $movement = DB::transaction(function () use ($validated, $idempotencyKey) {
                $product = Product::query()->lockForUpdate()->findOrFail($validated['product_id']);
                $this->unitConversions->assertPrecision((float) $validated['quantity'], $product->unit);
                $requestedQuantity = round((float) $validated['quantity'], 3);
                if ($requestedQuantity <= 0) {
                    throw ValidationException::withMessages(['quantity' => ['Quantity must be greater than zero.']]);
                }

                $movementCode = $validated['movement_code']
                    ?? ($validated['type'] === 'in' ? 'manual_adjustment_in' : 'manual_adjustment_out');
                if (! in_array($movementCode, self::MOVEMENT_CODES, true)) {
                    throw ValidationException::withMessages(['movement_code' => ['The selected stock movement code is invalid.']]);
                }

                if ($idempotencyKey) {
                    $existing = StockMovement::withoutGlobalScopes()
                        ->where('company_id', $product->company_id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($existing) {
                        $this->assertSameIdempotentMovement($existing, $product, $validated, $requestedQuantity, $movementCode);

                        return $existing;
                    }
                }

                $quantityBefore = round((float) $product->quantity, 3);

                $stockState = $validated['stock_state'] ?? 'available';
                $affectsCompanyQuantity = (bool) ($validated['affects_company_quantity'] ?? true);
                $warehouse = $this->warehouseInventory->resolveWarehouse(
                    $product,
                    isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
                    $validated['type'],
                    $requestedQuantity,
                    $stockState,
                );
                $requestedLocationId = isset($validated['location_id']) ? (int) $validated['location_id'] : null;
                // Seed a legacy product's established company balance before
                // resolving an outbound bin; otherwise location resolution sees
                // zero stock even though Product.quantity has an opening value.
                $hasAnyWarehouseBalance = $product->warehouseStock()->withoutGlobalScopes()->exists();
                $seedLocationId = ! $hasAnyWarehouseBalance && $quantityBefore > 0.0005
                    ? null
                    : $requestedLocationId;
                $this->warehouseInventory->ensureBalance($product, $warehouse, $seedLocationId);
                $locationId = $this->warehouseInventory->resolveLocationId(
                    $product,
                    $warehouse,
                    $validated['type'],
                    $requestedQuantity,
                    $stockState,
                    $requestedLocationId,
                );

                if ($affectsCompanyQuantity && $validated['type'] === 'out' && $quantityBefore + 0.0005 < $requestedQuantity) {
                    throw ValidationException::withMessages([
                        'quantity' => ["Insufficient stock. Only {$quantityBefore} units are currently available."],
                    ]);
                }

                $quantityAfter = $affectsCompanyQuantity
                    ? ($validated['type'] === 'in' ? $quantityBefore + $requestedQuantity : $quantityBefore - $requestedQuantity)
                    : $quantityBefore;

                $costSnapshot = $this->costing->movementSnapshot(
                    $product,
                    $validated['type'],
                    $requestedQuantity,
                    $quantityBefore,
                    $quantityAfter,
                    $affectsCompanyQuantity,
                    isset($validated['base_purchase_unit_cost']) ? (float) $validated['base_purchase_unit_cost'] : null,
                    isset($validated['landed_cost_unit']) ? (float) $validated['landed_cost_unit'] : null,
                    isset($validated['final_unit_cost']) ? (float) $validated['final_unit_cost'] : null,
                );

                if ($affectsCompanyQuantity) {
                    $product->update([
                        'quantity' => $quantityAfter,
                        'weighted_average_cost' => $costSnapshot['product_weighted_average_cost'],
                        'inventory_value' => $costSnapshot['product_inventory_value'],
                    ]);
                }
                $warehouseMutation = $this->warehouseInventory->mutate(
                    $product,
                    $warehouse,
                    $validated['type'],
                    $requestedQuantity,
                    $stockState,
                    $locationId,
                );

                $movement = $this->movements->create([
                    'company_id' => $product->company_id,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'location_id' => $locationId,
                    'source_warehouse_id' => $validated['source_warehouse_id'] ?? null,
                    'destination_warehouse_id' => $validated['destination_warehouse_id'] ?? null,
                    'stock_state' => $stockState,
                    'type' => $validated['type'],
                    'quantity' => $requestedQuantity,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'warehouse_quantity_before' => $warehouseMutation['before'],
                    'warehouse_quantity_after' => $warehouseMutation['after'],
                    'location_quantity_before' => $warehouseMutation['location_before'],
                    'location_quantity_after' => $warehouseMutation['location_after'],
                    'affects_company_quantity' => $affectsCompanyQuantity,
                    'reason' => $validated['reason'] ?? null,
                    'movement_code' => $movementCode,
                    'unit_snapshot' => $product->unit ?: 'pcs',
                    'source_type' => $validated['source_type'] ?? null,
                    'source_id' => $validated['source_id'] ?? null,
                    'performed_by' => $validated['performed_by'] ?? Auth::id(),
                    'idempotency_key' => $idempotencyKey,
                    'occurred_at' => $validated['occurred_at'] ?? now(),
                    'metadata' => $validated['metadata'] ?? null,
                    'base_purchase_unit_cost' => $costSnapshot['base_purchase_unit_cost'],
                    'landed_cost_unit' => $costSnapshot['landed_cost_unit'],
                    'final_unit_cost' => $costSnapshot['final_unit_cost'],
                    'cost_total' => $costSnapshot['cost_total'],
                    'weighted_average_cost_before' => $costSnapshot['weighted_average_cost_before'],
                    'weighted_average_cost_after' => $costSnapshot['weighted_average_cost_after'],
                    'inventory_value_before' => $costSnapshot['inventory_value_before'],
                    'inventory_value_after' => $costSnapshot['inventory_value_after'],
                ]);
                $this->traceability->applyMovement(
                    $movement,
                    $product,
                    $warehouse,
                    $locationId,
                    $stockState,
                    $validated['trace_allocations'] ?? [],
                );

                return $movement;
            });
        } catch (QueryException $exception) {
            if (! $idempotencyKey) {
                throw $exception;
            }

            // Two identical requests may both pass the initial lookup. The
            // database unique key is the final concurrency guard; after the
            // winning transaction commits, return its movement only when the
            // complete payload matches.
            $companyId = Auth::user()?->company_id;
            $existing = StockMovement::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            $product = Product::withoutGlobalScopes()->find($validated['product_id']);
            if (! $existing || ! $product) {
                throw $exception;
            }
            $movementCode = $validated['movement_code']
                ?? ($validated['type'] === 'in' ? 'manual_adjustment_in' : 'manual_adjustment_out');
            $this->assertSameIdempotentMovement(
                $existing,
                $product,
                $validated,
                round((float) $validated['quantity'], 3),
                $movementCode,
            );
            $movement = $existing;
        }

        $movement->load([
            'product.category', 'actor:id,name', 'warehouse:id,name,code',
            'location:id,name,code,path',
            'traceLines.lot.product:id,name,sku,tracking_mode,near_expiry_days',
        ]);

        if ($movement->wasRecentlyCreated) {
            $companyId = (int) $movement->company_id;
            $userName = Auth::user()?->name ?? 'System';
            $movementId = $movement->id;
            $movementType = $validated['type'];
            $afterCommit = function () use ($companyId, $userName, $movementId, $movementType): void {
                $committedMovement = StockMovement::withoutGlobalScopes()
                    ->with('product.category')->find($movementId);
                if (! $committedMovement || ! $committedMovement->product) {
                    return;
                }
                SafeBroadcast::dispatch(new StockUpdated($companyId, $committedMovement));
                SafeBroadcast::dispatch(new DashboardUpdated($companyId));
                $this->redisStore->invalidateDashboardStats($companyId);
                $this->redisStore->pushActivityEvent(
                    $companyId,
                    $movementType === 'in' ? 'stock_in' : 'stock_out',
                    'product',
                    $committedMovement->product_id,
                    $userName
                );

                if ($committedMovement->product->quantity <= $committedMovement->product->min_quantity) {
                    try {
                        $notification = $this->notifications->createLowStockAlert($committedMovement->product);
                        SafeBroadcast::dispatch(new LowStockDetected($companyId, $notification));
                    } catch (\Throwable $exception) {
                        Log::error('Low-stock notification could not be created.', [
                            'product_id' => $committedMovement->product_id,
                            'company_id' => $companyId,
                            'exception' => $exception,
                        ]);
                    }
                    $this->redisStore->addLowStockAlert($companyId, $committedMovement->product_id);
                } else {
                    $this->redisStore->removeLowStockAlert($companyId, $committedMovement->product_id);
                }
            };

            if (DB::transactionLevel() > 0) {
                DB::afterCommit($afterCommit);
            } else {
                $afterCommit();
            }
        }

        return $movement;
    }

    /**
     * Records a ledger-only opening/reconciliation entry without changing the
     * already established product balance. This is intentionally restricted to
     * the reconciliation command and preserves legacy business data.
     */
    public function recordReconciliationSnapshot(
        Product $product,
        float $quantityBefore,
        float $quantityAfter,
        string $movementCode,
        string $reason,
        string $idempotencyKey,
        mixed $occurredAt = null,
    ): StockMovement {
        if (! in_array($movementCode, ['opening_balance', 'reconciliation_adjustment'], true)) {
            throw new \InvalidArgumentException('Only opening or reconciliation movements may be recorded as ledger snapshots.');
        }

        return DB::transaction(function () use ($product, $quantityBefore, $quantityAfter, $movementCode, $reason, $idempotencyKey, $occurredAt) {
            $locked = Product::withoutGlobalScopes()->lockForUpdate()->findOrFail($product->id);
            $existing = StockMovement::withoutGlobalScopes()
                ->where('company_id', $locked->company_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            $before = round($quantityBefore, 3);
            $after = round($quantityAfter, 3);
            $difference = round($after - $before, 3);
            if (abs($difference) < 0.0005) {
                throw new \InvalidArgumentException('A reconciliation movement must change the ledger balance.');
            }

            return $this->movements->create([
                'company_id' => $locked->company_id,
                'product_id' => $locked->id,
                'type' => $difference > 0 ? 'in' : 'out',
                'quantity' => abs($difference),
                'quantity_before' => $before,
                'quantity_after' => $after,
                'reason' => $reason,
                'movement_code' => $movementCode,
                'unit_snapshot' => $locked->unit ?: 'pcs',
                'warehouse_id' => $locked->default_warehouse_id,
                'source_type' => 'system_reconciliation',
                'source_id' => $locked->id,
                'performed_by' => null,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => $occurredAt ?? now(),
                'metadata' => ['ledger_only' => true],
            ]);
        });
    }

    private function assertSameIdempotentMovement(
        StockMovement $existing,
        Product $product,
        array $requested,
        float $quantity,
        string $movementCode,
    ): void {
        $same = (int) $existing->product_id === (int) $product->id
            && $existing->type === $requested['type']
            && abs((float) $existing->quantity - $quantity) < 0.0005
            && $existing->movement_code === $movementCode
            && (! isset($requested['warehouse_id']) || (int) $existing->warehouse_id === (int) $requested['warehouse_id'])
            && (! array_key_exists('location_id', $requested) || (int) ($existing->location_id ?? 0) === (int) ($requested['location_id'] ?? 0))
            && (string) ($existing->stock_state ?? 'available') === (string) ($requested['stock_state'] ?? 'available')
            && (bool) ($existing->affects_company_quantity ?? true) === (bool) ($requested['affects_company_quantity'] ?? true)
            && (string) ($existing->source_type ?? '') === (string) ($requested['source_type'] ?? '')
            && (string) ($existing->source_id ?? '') === (string) ($requested['source_id'] ?? '');

        foreach (['base_purchase_unit_cost', 'landed_cost_unit', 'final_unit_cost'] as $costField) {
            if (array_key_exists($costField, $requested)) {
                $same = $same && abs((float) $existing->{$costField} - (float) $requested[$costField]) < 0.0000005;
            }
        }
        if (array_key_exists('trace_allocations', $requested) && $requested['trace_allocations'] !== []) {
            $same = $same && $this->canonicalRequestedTrace($product, $requested['trace_allocations'])
                === $this->canonicalMovementTrace($existing);
        }

        if (! $same) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['This idempotency key was already used for a different stock movement.'],
            ]);
        }
    }

    private function canonicalRequestedTrace(Product $product, array $allocations): string
    {
        $rows = collect($allocations)->map(function (array $allocation) use ($product): array {
            $lot = ! empty($allocation['inventory_lot_id'])
                ? InventoryLot::withoutGlobalScopes()
                    ->where('company_id', $product->company_id)
                    ->where('product_id', $product->id)
                    ->find($allocation['inventory_lot_id'])
                : null;
            $identity = $lot?->identity_key;
            if (! $identity) {
                $identity = match ($product->tracking_mode ?: 'none') {
                    'serial' => 'serial:'.mb_strtolower(trim((string) ($allocation['serial_number'] ?? ''))),
                    'batch_expiry' => 'batch:'.mb_strtolower(trim((string) ($allocation['lot_number'] ?? ''))).'|expiry:'.(string) ($allocation['expiry_at'] ?? $allocation['expiry_date'] ?? ''),
                    'batch' => 'batch:'.mb_strtolower(trim((string) ($allocation['lot_number'] ?? ''))),
                    default => '',
                };
            }

            return ['identity' => $identity, 'quantity' => round((float) ($allocation['quantity'] ?? 0), 3)];
        })->groupBy('identity')->map(fn ($group, $identity) => [
            'identity' => $identity,
            'quantity' => round((float) $group->sum('quantity'), 3),
        ])->sortBy('identity')->values()->all();

        return json_encode($rows, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }

    private function canonicalMovementTrace(StockMovement $movement): string
    {
        $rows = $movement->traceLines()->with('lot')->get()
            ->map(fn ($line) => [
                'identity' => (string) $line->lot->identity_key,
                'quantity' => round((float) $line->quantity, 3),
            ])->groupBy('identity')->map(fn ($group, $identity) => [
                'identity' => $identity,
                'quantity' => round((float) $group->sum('quantity'), 3),
            ])->sortBy('identity')->values()->all();

        return json_encode($rows, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
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
            fputcsv($handle, ['Date', 'Product', 'SKU', 'Warehouse', 'Location', 'Stock state', 'Movement', 'Type', 'Quantity', 'Unit', 'Company before', 'Company after', 'Warehouse before', 'Warehouse after', 'Source', 'Source ID', 'User', 'Reason']);

            foreach ($movements as $movement) {
                fputcsv($handle, [
                    ($movement->occurred_at ?? $movement->created_at)->toDateTimeString(),
                    $movement->product?->name ?? '',
                    $movement->product?->sku ?? '',
                    $movement->warehouse?->name ?? '',
                    $movement->location?->path ?? '',
                    $movement->stock_state ?? 'available',
                    $movement->movement_code,
                    $movement->type,
                    $movement->quantity,
                    $movement->unit_snapshot ?? $movement->product?->unit ?? '',
                    $movement->quantity_before,
                    $movement->quantity_after,
                    $movement->warehouse_quantity_before,
                    $movement->warehouse_quantity_after,
                    $movement->source_type ?? '',
                    $movement->source_id ?? '',
                    $movement->actor?->name ?? 'System',
                    $movement->reason ?? '',
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
