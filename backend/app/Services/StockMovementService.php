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
use Illuminate\Auth\Access\AuthorizationException;
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
        'state_transfer',
    ];

    /** Movement pairs that relocate/reclassify stock without changing ownership. */
    private const NON_COMPANY_MOVEMENT_CODES = [
        'transfer_out', 'transfer_in', 'transfer_return',
        'bin_transfer_out', 'bin_transfer_in',
        'reservation', 'reservation_release',
        'quarantine_in', 'quarantine_release', 'state_transfer',
        // A customer return received directly as scrap is recorded as an
        // atomic customer_return + damage_writeoff pair.
        'customer_return', 'damage_writeoff',
    ];

    public function __construct(
        private StockMovementRepositoryInterface $movements,
        private NotificationService $notifications,
        private RedisStoreService $redisStore,
        private WarehouseInventoryService $warehouseInventory,
        private UnitConversionService $unitConversions,
        private InventoryCostingService $costing,
        private TraceabilityService $traceability,
        private PermissionService $permissions,
        private InventoryIntegrityService $integrity,
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

                if (in_array($movementCode, ['manual_adjustment_in', 'manual_adjustment_out'], true)) {
                    $this->assertManualAdjustmentContext($validated, $movementCode);
                }

                $expiredOverride = (bool) ($validated['allow_expired_override'] ?? false);
                $overrideReason = trim((string) ($validated['expired_override_reason'] ?? ''));
                if ($expiredOverride) {
                    $user = Auth::user();
                    if (! $user || ! $this->permissions->roleHasPermission($user->role, 'inventory.expired.override')) {
                        throw new AuthorizationException('You are not authorized to issue expired inventory.');
                    }
                    if (mb_strlen($overrideReason) < 5) {
                        throw ValidationException::withMessages([
                            'expired_override_reason' => ['Explain why expired stock must be issued.'],
                        ]);
                    }
                }

                $expiredReceiptOverride = (bool) ($validated['allow_expired_receipt'] ?? false);
                $expiredReceiptReason = trim((string) ($validated['expired_receipt_reason'] ?? ''));
                if ($expiredReceiptOverride) {
                    $user = Auth::user();
                    if ($validated['type'] !== 'in'
                        || ! in_array($movementCode, ['purchase_receipt', 'damage_received'], true)) {
                        throw ValidationException::withMessages([
                            'allow_expired_receipt' => ['Expired-receipt authorization is only valid for a Purchase Order receipt.'],
                        ]);
                    }
                    if (! $user || ! $this->permissions->roleHasPermission($user->role, 'inventory.expired.override')) {
                        throw new AuthorizationException('You are not authorized to receive expired inventory.');
                    }
                    if (mb_strlen($expiredReceiptReason) < 5) {
                        throw ValidationException::withMessages([
                            'expired_receipt_reason' => ['Explain why expired stock is being accepted.'],
                        ]);
                    }
                }

                if (($product->lifecycle_status ?? 'active') === 'archived'
                    && ! in_array($movementCode, [
                        'daily_sale_reversal', 'invoice_credit', 'customer_return', 'supplier_return',
                        'transfer_return', 'reconciliation_adjustment', 'stock_count',
                    ], true)) {
                    throw ValidationException::withMessages([
                        'product_id' => ['Archived products cannot be used in normal inventory operations.'],
                    ]);
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

                if ($movementCode === 'opening_balance') {
                    $hasStockHistory = StockMovement::withoutGlobalScopes()
                        ->where('company_id', $product->company_id)
                        ->where('product_id', $product->id)
                        ->exists();
                    if ($validated['type'] !== 'in'
                        || $hasStockHistory
                        || abs((float) $product->quantity) >= 0.0005
                        || (($validated['affects_company_quantity'] ?? true) === false)) {
                        throw ValidationException::withMessages([
                            'movement_code' => [
                                'Opening inventory can only be recorded once, while the product is first created. Use a stock adjustment afterwards.',
                            ],
                        ]);
                    }
                }

                $quantityBefore = round((float) $product->quantity, 3);

                $stockState = $validated['stock_state'] ?? 'available';
                $affectsCompanyQuantity = (bool) ($validated['affects_company_quantity'] ?? true);
                if (! $affectsCompanyQuantity && ! in_array($movementCode, self::NON_COMPANY_MOVEMENT_CODES, true)) {
                    throw ValidationException::withMessages([
                        'affects_company_quantity' => [
                            'Only controlled transfers or stock-state transitions may leave company quantity unchanged.',
                        ],
                    ]);
                }
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
                if ($affectsCompanyQuantity) {
                    $this->integrity->assertProductReconciled($product);
                }
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

                $metadata = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];
                if ($expiredOverride) {
                    $metadata['expired_stock_override'] = [
                        'authorized_by' => Auth::id(),
                        'reason' => $overrideReason,
                        'authorized_at' => now()->toIso8601String(),
                    ];
                }
                if ($expiredReceiptOverride) {
                    $metadata['expired_receipt_override'] = [
                        'authorized_by' => Auth::id(),
                        'reason' => $expiredReceiptReason,
                        'authorized_at' => now()->toIso8601String(),
                    ];
                }

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
                    'metadata' => $metadata ?: null,
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
                    $expiredOverride,
                    $expiredReceiptOverride,
                );
                if ($affectsCompanyQuantity) {
                    $this->integrity->assertProductReconciled($product);
                }

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

                if ($this->notifications->isLowStock($committedMovement->product)) {
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
                    $this->notifications->resolveLowStockAlert($committedMovement->product);
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
     * Record one logical outbound operation as one movement per physical bin.
     * No aggregate movement is created. Tracked products are allocated FEFO
     * across eligible lot/bin balances; untracked products consume eligible
     * bin balances in deterministic order.
     *
     * @return Collection<int, StockMovement>
     */
    public function storeOutboundAllocated(array $data): Collection
    {
        return DB::transaction(function () use ($data): Collection {
            if (($data['type'] ?? 'out') !== 'out') {
                throw ValidationException::withMessages(['type' => ['Allocated inventory movements must be outbound.']]);
            }
            $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
            if ($idempotencyKey === '') {
                throw ValidationException::withMessages(['idempotency_key' => ['An idempotency key is required for an allocated outbound operation.']]);
            }

            $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            $quantity = round((float) ($data['quantity'] ?? 0), 3);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['quantity' => ['Quantity must be greater than zero.']]);
            }
            $warehouse = $this->warehouseInventory->resolveWarehouse(
                $product,
                isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
                'out',
                $quantity,
                (string) ($data['stock_state'] ?? 'available'),
            );

            $existing = StockMovement::withoutGlobalScopes()
                ->where('company_id', $product->company_id)
                ->where(function ($query) use ($idempotencyKey): void {
                    $query->where('idempotency_key', $idempotencyKey)
                        ->orWhere('idempotency_key', 'like', $idempotencyKey.'-part-%');
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($existing->isNotEmpty()) {
                $same = abs(round((float) $existing->sum('quantity'), 3) - $quantity) < 0.0005
                    && $existing->every(fn (StockMovement $movement) =>
                        (int) $movement->product_id === (int) $product->id
                        && (int) $movement->warehouse_id === (int) $warehouse->id
                        && $movement->type === 'out'
                        && $movement->movement_code === ($data['movement_code'] ?? 'manual_adjustment_out')
                        && $movement->stock_state === ($data['stock_state'] ?? 'available')
                        && (bool) $movement->affects_company_quantity === (bool) ($data['affects_company_quantity'] ?? true)
                        && (string) ($movement->source_type ?? '') === (string) ($data['source_type'] ?? '')
                        && (int) ($movement->source_id ?? 0) === (int) ($data['source_id'] ?? 0)
                        && trim((string) ($movement->reason ?? '')) === trim((string) ($data['reason'] ?? ''))
                    );
                if ($same && array_key_exists('location_id', $data)) {
                    $same = $existing->every(fn (StockMovement $movement) =>
                        (int) ($movement->location_id ?? 0) === (int) ($data['location_id'] ?? 0)
                    );
                }
                if ($same && ! empty($data['location_allocations'])) {
                    $requestedLocations = collect($data['location_allocations'])
                        ->groupBy(fn (array $row) => (string) ((int) ($row['location_id'] ?? 0)))
                        ->map(fn ($rows, $locationId) => [
                            'location_id' => (int) $locationId,
                            'quantity' => round((float) $rows->sum('quantity'), 3),
                        ])->sortBy('location_id')->values()->all();
                    $existingLocations = $existing
                        ->groupBy(fn (StockMovement $movement) => (string) ((int) ($movement->location_id ?? 0)))
                        ->map(fn ($rows, $locationId) => [
                            'location_id' => (int) $locationId,
                            'quantity' => round((float) $rows->sum('quantity'), 3),
                        ])->sortBy('location_id')->values()->all();
                    $same = $requestedLocations === $existingLocations;
                }
                if ($same && ! empty($data['trace_allocations'])) {
                    $same = $this->canonicalRequestedTrace($product, $data['trace_allocations'])
                        === $this->canonicalMovementsTrace($existing);
                }
                if ($same) {
                    foreach (['base_purchase_unit_cost', 'landed_cost_unit', 'final_unit_cost'] as $costField) {
                        if (array_key_exists($costField, $data)) {
                            $same = $existing->every(fn (StockMovement $movement) =>
                                abs((float) $movement->{$costField} - (float) $data[$costField]) < 0.0000005
                            );
                        }
                    }
                }
                if ($same) {
                    $same = $existing->every(function (StockMovement $movement) use ($data): bool {
                        $override = (array) data_get($movement->metadata, 'expired_stock_override', []);

                        return (bool) ($data['allow_expired_override'] ?? false) === ($override !== [])
                            && (! ($data['allow_expired_override'] ?? false)
                                || trim((string) ($data['expired_override_reason'] ?? '')) === trim((string) ($override['reason'] ?? '')));
                    });
                }
                if (! $same) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This idempotency key was already used for a different allocated stock operation.'],
                    ]);
                }

                return $existing->load(['traceLines', 'warehouse', 'location']);
            }

            $locationId = isset($data['location_id']) ? (int) $data['location_id'] : null;
            $state = (string) ($data['stock_state'] ?? 'available');
            $explicitLocations = collect($data['location_allocations'] ?? [])
                ->map(fn (array $row) => [
                    'location_id' => isset($row['location_id']) ? (int) $row['location_id'] : null,
                    'quantity' => round((float) ($row['quantity'] ?? 0), 3),
                ])
                ->filter(fn (array $row) => $row['quantity'] > 0)
                ->groupBy(fn (array $row) => (string) ($row['location_id'] ?? 0))
                ->map(fn ($rows) => [
                    'location_id' => $rows->first()['location_id'],
                    'quantity' => round((float) $rows->sum('quantity'), 3),
                    'trace_allocations' => [],
                ])->values();
            if ($explicitLocations->isNotEmpty()
                && abs(round((float) $explicitLocations->sum('quantity'), 3) - $quantity) >= 0.0005) {
                throw ValidationException::withMessages(['location_allocations' => ['Bin allocations must equal the outbound quantity.']]);
            }

            $tracked = ($product->tracking_mode ?: 'none') !== 'none';
            if ($tracked) {
                $plans = collect($this->traceability->outboundLocationAllocations(
                    $product,
                    $warehouse,
                    $locationId,
                    $state,
                    $quantity,
                    $data['trace_allocations'] ?? [],
                    (bool) ($data['allow_expired_override'] ?? false),
                ));
                if ($explicitLocations->isNotEmpty()) {
                    $expected = $explicitLocations->keyBy(fn ($row) => (string) ($row['location_id'] ?? 0));
                    $actual = $plans->keyBy(fn ($row) => (string) ($row['location_id'] ?? 0));
                    if ($expected->keys()->sort()->values()->all() !== $actual->keys()->sort()->values()->all()
                        || $expected->contains(fn ($row, $key) => abs((float) $row['quantity'] - (float) $actual->get($key)['quantity']) >= 0.0005)) {
                        throw ValidationException::withMessages(['location_allocations' => ['Picked bin quantities do not match the selected lot or serial balances.']]);
                    }
                }
            } elseif ($explicitLocations->isNotEmpty()) {
                $plans = $explicitLocations;
            } else {
                $plans = collect($this->warehouseInventory->outboundLocationAllocations(
                    $product,
                    $warehouse,
                    $quantity,
                    $state,
                    $locationId,
                ))->map(fn (array $row) => $row + ['trace_allocations' => []]);
            }

            $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $movements = new Collection();
            foreach ($plans->values() as $index => $plan) {
                $part = $index + 1;
                $movementData = $data;
                unset($movementData['location_allocations']);
                $movementData['warehouse_id'] = $warehouse->id;
                $movementData['location_id'] = $plan['location_id'];
                $movementData['quantity'] = $plan['quantity'];
                $movementData['trace_allocations'] = $plan['trace_allocations'] ?? [];
                $movementData['idempotency_key'] = $idempotencyKey.'-part-'.$part;
                $movementData['metadata'] = array_merge($metadata, [
                    'logical_idempotency_key' => $idempotencyKey,
                    'allocation_part' => $part,
                    'allocation_parts' => $plans->count(),
                ]);
                $movements->push($this->store($movementData));
            }

            return $movements;
        });
    }

    /**
     * Atomically reclassify physical stock between available/reserved/damaged/
     * quarantine/blocked states. The paired ledger entries preserve company
     * quantity, warehouse on-hand, trace identity, and auditability.
     *
     * @return array{out_movement: StockMovement, in_movement: StockMovement}
     */
    public function transitionState(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            $fromState = (string) ($data['from_state'] ?? 'available');
            $toState = (string) ($data['to_state'] ?? 'reserved');
            if (! in_array($fromState, WarehouseInventoryService::STATES, true)
                || ! in_array($toState, WarehouseInventoryService::STATES, true)
                || $fromState === $toState) {
                throw ValidationException::withMessages([
                    'to_state' => ['Select two different valid inventory states.'],
                ]);
            }
            $quantity = round((float) ($data['quantity'] ?? 0), 3);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['quantity' => ['Quantity must be greater than zero.']]);
            }
            $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
            if ($idempotencyKey === '') {
                throw ValidationException::withMessages(['idempotency_key' => ['An idempotency key is required.']]);
            }

            $movementCode = $this->stateTransitionCode($fromState, $toState);
            $existingPair = StockMovement::withoutGlobalScopes()
                ->where('company_id', $product->company_id)
                ->whereIn('idempotency_key', [$idempotencyKey.'-out', $idempotencyKey.'-in'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($existingPair->isNotEmpty()) {
                $expectedSourceType = (string) ($data['source_type'] ?? 'inventory_state_transition');
                $expectedSourceId = (string) ($data['source_id'] ?? '');
                $expectedReason = trim((string) ($data['reason'] ?? 'Inventory state transition'));
                $out = $existingPair->firstWhere('idempotency_key', $idempotencyKey.'-out');
                $in = $existingPair->firstWhere('idempotency_key', $idempotencyKey.'-in');
                $same = $existingPair->count() === 2 && $out && $in
                    && (int) $out->product_id === (int) $product->id
                    && (int) $in->product_id === (int) $product->id
                    && $out->type === 'out' && $in->type === 'in'
                    && $out->stock_state === $fromState && $in->stock_state === $toState
                    && $out->movement_code === $movementCode && $in->movement_code === $movementCode
                    && abs((float) $out->quantity - $quantity) < 0.0005
                    && abs((float) $in->quantity - $quantity) < 0.0005
                    && ! $out->affects_company_quantity && ! $in->affects_company_quantity
                    && (string) ($out->source_type ?? '') === $expectedSourceType
                    && (string) ($in->source_type ?? '') === $expectedSourceType
                    && (string) ($out->source_id ?? '') === $expectedSourceId
                    && (string) ($in->source_id ?? '') === $expectedSourceId
                    && trim((string) $out->reason) === $expectedReason
                    && trim((string) $in->reason) === $expectedReason
                    && (! isset($data['warehouse_id'])
                        || ((int) $out->warehouse_id === (int) $data['warehouse_id']
                            && (int) $in->warehouse_id === (int) $data['warehouse_id']))
                    && (! array_key_exists('location_id', $data)
                        || ((int) ($out->location_id ?? 0) === (int) ($data['location_id'] ?? 0)
                            && (int) ($in->location_id ?? 0) === (int) ($data['location_id'] ?? 0)));
                if ($same && ! empty($data['trace_allocations'])) {
                    $same = $this->canonicalRequestedTrace($product, $data['trace_allocations'])
                        === $this->canonicalMovementTrace($out);
                }
                if (! $same) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This idempotency key was already used for a different stock-state transition.'],
                    ]);
                }

                return [
                    'out_movement' => $out->load(['traceLines', 'warehouse', 'location']),
                    'in_movement' => $in->load(['traceLines', 'warehouse', 'location']),
                ];
            }

            $warehouse = $this->warehouseInventory->resolveWarehouse(
                $product,
                isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
                'out',
                $quantity,
                $fromState,
            );
            $locationId = $this->warehouseInventory->resolveLocationId(
                $product,
                $warehouse,
                'out',
                $quantity,
                $fromState,
                isset($data['location_id']) ? (int) $data['location_id'] : null,
            );
            $this->integrity->assertProductReconciled($product);

            $common = [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $locationId,
                'quantity' => $quantity,
                'affects_company_quantity' => false,
                'movement_code' => $movementCode,
                'source_type' => $data['source_type'] ?? 'inventory_state_transition',
                'source_id' => $data['source_id'] ?? null,
                'reason' => trim((string) ($data['reason'] ?? 'Inventory state transition')),
                'metadata' => [
                    ...(is_array($data['metadata'] ?? null) ? $data['metadata'] : []),
                    'from_state' => $fromState,
                    'to_state' => $toState,
                    'transition_key' => $idempotencyKey,
                ],
            ];
            $out = $this->store([
                ...$common,
                'type' => 'out',
                'stock_state' => $fromState,
                'idempotency_key' => $idempotencyKey.'-out',
                'trace_allocations' => $data['trace_allocations'] ?? [],
            ]);
            $traceAllocations = $out->traceLines->map(fn ($line) => [
                'inventory_lot_id' => $line->inventory_lot_id,
                'quantity' => (float) $line->quantity,
            ])->all();
            $in = $this->store([
                ...$common,
                'type' => 'in',
                'stock_state' => $toState,
                'idempotency_key' => $idempotencyKey.'-in',
                'trace_allocations' => $traceAllocations,
            ]);

            $this->integrity->assertProductReconciled($product);

            return ['out_movement' => $out, 'in_movement' => $in];
        });
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

    private function stateTransitionCode(string $fromState, string $toState): string
    {
        return match ([$fromState, $toState]) {
            ['available', 'reserved'] => 'reservation',
            ['reserved', 'available'] => 'reservation_release',
            ['available', 'quarantine'] => 'quarantine_in',
            ['quarantine', 'available'] => 'quarantine_release',
            default => 'state_transfer',
        };
    }

    /**
     * Manual corrections are intentionally stricter than automated business
     * movements: an accountable user must identify the exact warehouse/bin
     * and explain why the physical balance is changing.
     */
    private function assertManualAdjustmentContext(array $data, string $movementCode): void
    {
        $expectedType = $movementCode === 'manual_adjustment_in' ? 'in' : 'out';
        if (($data['type'] ?? null) !== $expectedType) {
            throw ValidationException::withMessages([
                'type' => ['The adjustment direction does not match its movement code.'],
            ]);
        }
        if (empty($data['warehouse_id'])) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['Select the warehouse whose physical stock is being adjusted.'],
            ]);
        }
        if (empty($data['location_id'])) {
            throw ValidationException::withMessages([
                'location_id' => ['Select the exact warehouse location whose physical stock is being adjusted.'],
            ]);
        }
        if (mb_strlen(trim((string) ($data['reason'] ?? ''))) < 3) {
            throw ValidationException::withMessages([
                'reason' => ['Explain the reason for this stock adjustment.'],
            ]);
        }
        if (($data['source_type'] ?? null) !== 'manual_adjustment') {
            throw ValidationException::withMessages([
                'source_type' => ['Manual corrections must be recorded through the dedicated stock-adjustment workflow.'],
            ]);
        }

        $user = Auth::user();
        if (! $user || ! $this->permissions->roleHasPermission($user->role, 'stock.manage')) {
            throw new AuthorizationException('You are not authorized to adjust inventory.');
        }
        if (isset($data['performed_by']) && (int) $data['performed_by'] !== (int) $user->id) {
            throw new AuthorizationException('A stock adjustment cannot be recorded as another user.');
        }
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
            && (string) ($existing->source_id ?? '') === (string) ($requested['source_id'] ?? '')
            && trim((string) ($existing->reason ?? '')) === trim((string) ($requested['reason'] ?? ''));

        foreach (['base_purchase_unit_cost', 'landed_cost_unit', 'final_unit_cost'] as $costField) {
            if (array_key_exists($costField, $requested)) {
                $same = $same && abs((float) $existing->{$costField} - (float) $requested[$costField]) < 0.0000005;
            }
        }
        if (array_key_exists('trace_allocations', $requested) && $requested['trace_allocations'] !== []) {
            $same = $same && $this->canonicalRequestedTrace($product, $requested['trace_allocations'])
                === $this->canonicalMovementTrace($existing);
        }
        $existingOverride = (array) data_get($existing->metadata, 'expired_stock_override', []);
        $same = $same
            && (bool) ($requested['allow_expired_override'] ?? false) === ($existingOverride !== [])
            && (! ($requested['allow_expired_override'] ?? false)
                || trim((string) ($requested['expired_override_reason'] ?? '')) === trim((string) ($existingOverride['reason'] ?? '')));
        $existingReceiptOverride = (array) data_get($existing->metadata, 'expired_receipt_override', []);
        $same = $same
            && (bool) ($requested['allow_expired_receipt'] ?? false) === ($existingReceiptOverride !== [])
            && (! ($requested['allow_expired_receipt'] ?? false)
                || trim((string) ($requested['expired_receipt_reason'] ?? '')) === trim((string) ($existingReceiptOverride['reason'] ?? '')));

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

    /** @param Collection<int, StockMovement> $movements */
    private function canonicalMovementsTrace(Collection $movements): string
    {
        $rows = $movements->flatMap(fn (StockMovement $movement) =>
            $movement->traceLines()->with('lot')->get()->map(fn ($line) => [
                'identity' => (string) $line->lot->identity_key,
                'quantity' => round((float) $line->quantity, 3),
            ])
        )->groupBy('identity')->map(fn ($group, $identity) => [
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
