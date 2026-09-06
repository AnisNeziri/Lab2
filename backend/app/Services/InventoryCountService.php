<?php

namespace App\Services;

use App\Models\InventoryCountEntry;
use App\Models\InventoryCountItem;
use App\Models\InventoryCountSession;
use App\Models\InventoryLot;
use App\Models\InventoryTraceBalance;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseStock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryCountService
{
    public const STATUSES = ['in_progress', 'recount_required', 'submitted', 'approved', 'cancelled'];

    public function __construct(
        private readonly StockMovementService $movements,
        private readonly TraceabilityService $traceability,
        private readonly UnitConversionService $units,
        private readonly BusinessEventService $events,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        return InventoryCountSession::query()
            ->with(['warehouse:id,name,code', 'location:id,name,code,path', 'creator:id,name', 'approver:id,name'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function find(InventoryCountSession $session): InventoryCountSession
    {
        return $session->load([
            'warehouse', 'location', 'creator:id,name', 'submitter:id,name', 'approver:id,name',
            'items' => fn ($query) => $query->with([
                'product:id,name,sku,unit,tracking_mode,near_expiry_days',
                'location:id,name,code,path',
                'lot.product:id,name,sku,tracking_mode,near_expiry_days',
                'entries.user:id,name',
                'adjustmentMovement.traceLines.lot',
            ])->orderBy('location_key')->orderBy('product_id')->orderBy('lot_key')->orderBy('stock_state'),
        ]);
    }

    public function create(array $data): InventoryCountSession
    {
        return DB::transaction(function () use ($data): InventoryCountSession {
            $companyId = (int) Auth::user()->company_id;
            $warehouse = Warehouse::query()->where('is_active', true)->findOrFail($data['warehouse_id']);
            $locationId = isset($data['location_id']) ? (int) $data['location_id'] : null;
            if ($locationId !== null && ! WarehouseLocation::query()
                ->whereKey($locationId)->where('warehouse_id', $warehouse->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['location_id' => ['Select an active location in the count warehouse.']]);
            }
            $states = array_values(array_unique($data['stock_states'] ?? ['available']));
            $scopeProductIds = collect($data['product_ids'] ?? [])
                ->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all();
            $scopeAllProducts = $scopeProductIds === [];
            foreach ($states as $state) {
                if (! in_array($state, WarehouseInventoryService::STATES, true)) {
                    throw ValidationException::withMessages(['stock_states' => ['One or more stock states are invalid.']]);
                }
            }

            // Product creation is serialized on the company row. A complete
            // warehouse snapshot takes that lock too, then locks products in
            // the same order used by stock movements before reading bins.
            if ($scopeAllProducts) {
                DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            }
            $snapshotProducts = Product::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->when(! $scopeAllProducts, fn ($query) => $query->whereIn('id', $scopeProductIds))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if (! $scopeAllProducts && $snapshotProducts->count() !== count($scopeProductIds)) {
                throw ValidationException::withMessages([
                    'product_ids' => ['One or more selected products do not belong to this company.'],
                ]);
            }

            $session = InventoryCountSession::create([
                'company_id' => $companyId,
                'count_number' => $this->nextNumber($companyId),
                'warehouse_id' => $warehouse->id,
                'location_id' => $locationId,
                'status' => 'in_progress',
                'stock_states' => $states,
                'scope_all_products' => $scopeAllProducts,
                'scope_product_ids' => $scopeAllProducts ? null : $scopeProductIds,
                'frozen_at' => now(),
                'created_by' => Auth::id(),
                'notes' => $data['notes'] ?? null,
            ]);

            $stockRows = WarehouseStock::withoutGlobalScopes()
                ->with('product')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouse->id)
                ->when($locationId !== null, fn ($query) => $query->where('location_key', $locationId))
                ->when(! $scopeAllProducts, fn ($query) => $query->whereIn('product_id', $scopeProductIds))
                ->lockForUpdate()
                ->get();

            foreach ($stockRows as $stock) {
                foreach ($states as $state) {
                    $expected = round((float) $stock->{$state.'_quantity'}, 3);
                    if (($stock->product->tracking_mode ?: 'none') === 'none') {
                        $this->createItem($session, $stock->product, $stock->location_id, $state, $expected);
                        continue;
                    }

                    $traceRows = InventoryTraceBalance::withoutGlobalScopes()
                        ->where('company_id', $companyId)
                        ->where('warehouse_id', $warehouse->id)
                        ->where('location_key', (int) ($stock->location_id ?? 0))
                        ->where('stock_state', $state)
                        ->whereHas('lot', fn ($query) => $query->where('product_id', $stock->product_id))
                        ->lockForUpdate()
                        ->get();
                    $traceExpected = round((float) $traceRows->sum('quantity'), 3);
                    if (abs($traceExpected - $expected) >= 0.0005) {
                        throw ValidationException::withMessages([
                            'warehouse_id' => ["Tracked balances for {$stock->product->name} do not reconcile with its bin balance. Reconcile trace data before starting the count."],
                        ]);
                    }
                    foreach ($traceRows as $traceRow) {
                        $this->createItem(
                            $session,
                            $stock->product,
                            $stock->location_id,
                            $state,
                            (float) $traceRow->quantity,
                            (int) $traceRow->inventory_lot_id,
                        );
                    }
                }
            }

            foreach ($scopeProductIds as $productId) {
                $product = $snapshotProducts->firstWhere('id', $productId);
                $alreadyIncluded = $session->items()->where('product_id', $product->id)->exists();
                if (! $alreadyIncluded) {
                    if (($product->tracking_mode ?: 'none') !== 'none') {
                        continue;
                    }
                    foreach ($states as $state) {
                        $this->createItem($session, $product, $locationId, $state, 0);
                    }
                }
            }

            $session->update([
                'snapshot_identity_keys' => $session->items()->get([
                    'product_id', 'location_key', 'lot_key', 'stock_state',
                ])->map(fn (InventoryCountItem $item) => $this->identityKey(
                    (int) $item->product_id,
                    (int) $item->location_key,
                    (int) $item->lot_key,
                    (string) $item->stock_state,
                ))->sort()->values()->all(),
            ]);

            return $this->find($session->fresh());
        });
    }

    public function record(InventoryCountSession $session, array $data): InventoryCountSession
    {
        return DB::transaction(function () use ($session, $data): InventoryCountSession {
            $session = InventoryCountSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, ['in_progress', 'recount_required'], true)) {
                throw ValidationException::withMessages(['status' => ['Only an active count can accept count entries.']]);
            }

            foreach ($data['items'] as $input) {
                $item = ! empty($input['count_item_id'])
                    ? InventoryCountItem::query()
                        ->where('inventory_count_session_id', $session->id)
                        ->lockForUpdate()->findOrFail($input['count_item_id'])
                    : $this->unexpectedItem($session, $input);
                $product = Product::query()->findOrFail($item->product_id);
                $counted = round((float) $input['counted_quantity'], 3);
                $this->units->assertPrecision($counted, $product->unit, 'items');
                if ($counted < 0) {
                    throw ValidationException::withMessages(['items' => ['Counted quantity cannot be negative.']]);
                }
                if ($product->tracking_mode === 'serial' && $counted > 1.0005) {
                    throw ValidationException::withMessages(['items' => ['A serial-controlled count item can only be zero or one.']]);
                }

                $entryType = $item->requires_recount || $item->count_round > 1 ? 'recount' : 'count';
                InventoryCountEntry::create([
                    'company_id' => $session->company_id,
                    'inventory_count_item_id' => $item->id,
                    'count_round' => $item->count_round,
                    'entry_type' => $entryType,
                    'counted_quantity' => $counted,
                    'notes' => $input['notes'] ?? null,
                    'entered_by' => Auth::id(),
                    'entered_at' => now(),
                ]);
                $item->update([
                    'counted_quantity' => $counted,
                    'variance_quantity' => round($counted - (float) $item->expected_quantity, 3),
                    'requires_recount' => false,
                    'last_counted_by' => Auth::id(),
                    'last_counted_at' => now(),
                ]);
            }

            $session->update([
                'status' => $session->items()->where('requires_recount', true)->exists()
                    ? 'recount_required'
                    : 'in_progress',
            ]);

            return $this->find($session->fresh());
        });
    }

    public function requestRecount(InventoryCountSession $session, array $itemIds, string $reason): InventoryCountSession
    {
        return DB::transaction(function () use ($session, $itemIds, $reason): InventoryCountSession {
            $session = InventoryCountSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, ['in_progress', 'submitted', 'recount_required'], true)) {
                throw ValidationException::withMessages(['status' => ['This count can no longer be sent for recount.']]);
            }
            $requestedIds = collect($itemIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
            $allItems = $session->items()->orderBy('id')->lockForUpdate()->get();
            if ($allItems->whereIn('id', $requestedIds)->count() !== $requestedIds->count()) {
                throw ValidationException::withMessages(['item_ids' => ['One or more count items do not belong to this session.']]);
            }

            $scopeProductIds = $session->scope_all_products
                ? null
                : collect($session->scope_product_ids ?: $allItems->pluck('product_id'))
                    ->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all();
            if ($session->scope_all_products) {
                DB::table('companies')->where('id', $session->company_id)->lockForUpdate()->first();
            }
            $products = Product::withoutGlobalScopes()
                ->where('company_id', $session->company_id)
                ->when($scopeProductIds !== null, fn ($query) => $query->whereIn('id', $scopeProductIds))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $definitions = $this->snapshotDefinitions($session, $products, $scopeProductIds);
            $existingByKey = $allItems->keyBy(fn (InventoryCountItem $item) => $this->identityKey(
                (int) $item->product_id,
                (int) $item->location_key,
                (int) $item->lot_key,
                (string) $item->stock_state,
            ));

            foreach ($definitions as $key => $definition) {
                if ($existingByKey->has($key)) {
                    continue;
                }
                $newItem = $this->createItem(
                    $session,
                    $definition['product'],
                    $definition['location_id'],
                    $definition['stock_state'],
                    $definition['expected_quantity'],
                    $definition['inventory_lot_id'],
                );
                $newItem->update(['requires_recount' => true]);
                $allItems->push($newItem->fresh());
                $existingByKey->put($key, $newItem->fresh());
            }

            foreach ($allItems as $item) {
                $key = $this->identityKey(
                    (int) $item->product_id,
                    (int) $item->location_key,
                    (int) $item->lot_key,
                    (string) $item->stock_state,
                );
                $currentExpected = round((float) ($definitions->get($key)['expected_quantity'] ?? 0), 3);
                $scopeChanged = abs($currentExpected - (float) $item->expected_quantity) >= 0.0005;
                if (! $scopeChanged && ! $requestedIds->contains((int) $item->id)) {
                    continue;
                }
                InventoryCountEntry::create([
                    'company_id' => $session->company_id,
                    'inventory_count_item_id' => $item->id,
                    'count_round' => $item->count_round,
                    'entry_type' => 'recount_requested',
                    'counted_quantity' => $item->counted_quantity,
                    'notes' => $reason." (book quantity refreshed from {$item->expected_quantity} to {$currentExpected})",
                    'entered_by' => Auth::id(),
                    'entered_at' => now(),
                ]);
                $item->update([
                    'count_round' => $item->count_round + 1,
                    'expected_quantity' => $currentExpected,
                    'counted_quantity' => null,
                    'variance_quantity' => null,
                    'requires_recount' => true,
                ]);
            }

            $refreshedItems = $session->items()->get();
            $snapshotKeys = $definitions->keys()->merge(
                $refreshedItems
                    ->filter(fn (InventoryCountItem $item) => abs((float) $item->expected_quantity) < 0.0005)
                    ->map(fn (InventoryCountItem $item) => $this->identityKey(
                        (int) $item->product_id,
                        (int) $item->location_key,
                        (int) $item->lot_key,
                        (string) $item->stock_state,
                    )),
            )->unique()->sort()->values()->all();
            $session->update([
                'status' => 'recount_required',
                'snapshot_identity_keys' => $snapshotKeys,
                'frozen_at' => now(),
                'submitted_at' => null,
                'submitted_by' => null,
            ]);

            return $this->find($session->fresh());
        });
    }

    public function submit(InventoryCountSession $session): InventoryCountSession
    {
        return DB::transaction(function () use ($session): InventoryCountSession {
            $session = InventoryCountSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, ['in_progress', 'recount_required'], true)) {
                throw ValidationException::withMessages(['status' => ['Only an active count can be submitted.']]);
            }
            if (! $session->items()->exists()) {
                throw ValidationException::withMessages(['items' => ['This count has no items.']]);
            }
            if ($session->items()->where(fn ($query) => $query->whereNull('counted_quantity')->orWhere('requires_recount', true))->exists()) {
                throw ValidationException::withMessages(['items' => ['Count or recount every item before submitting.']]);
            }
            $session->update(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => Auth::id()]);

            return $this->find($session->fresh());
        });
    }

    public function approve(InventoryCountSession $session, string $reason): InventoryCountSession
    {
        return DB::transaction(function () use ($session, $reason): InventoryCountSession {
            $session = InventoryCountSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($session->status !== 'submitted') {
                throw ValidationException::withMessages(['status' => ['Only a submitted count can be approved.']]);
            }
            $items = $session->items()->with('product')->lockForUpdate()->get();
            // Stock movements lock Product before warehouse/bin balances. Use
            // the same lock order so an approval cannot race a sale, receipt,
            // transfer, or adjustment while its stale-snapshot check runs.
            if ($session->scope_all_products) {
                // Product creation takes this same company lock. Together with
                // all existing product locks it closes the new-product gap in
                // a warehouse-wide count while the scope is revalidated.
                DB::table('companies')->where('id', $session->company_id)->lockForUpdate()->first();
            }
            $scopeProductIds = $session->scope_all_products
                ? null
                : collect($session->scope_product_ids ?: $items->pluck('product_id'))
                    ->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all();
            $lockedProducts = Product::withoutGlobalScopes()
                ->where('company_id', $session->company_id)
                ->when($scopeProductIds !== null, fn ($query) => $query->whereIn('id', $scopeProductIds))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->assertSnapshotScopeUnchanged($session, $items, $lockedProducts, $scopeProductIds);
            foreach ($items as $item) {
                $current = $this->currentBalance($session, $item, true);
                if (abs($current - (float) $item->expected_quantity) >= 0.0005) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Inventory changed after {$session->count_number} captured its snapshot for {$item->product->name}. "
                            .'Request a recount before approving so the adjustment cannot corrupt live stock.',
                        ],
                    ]);
                }
            }
            foreach ($items as $item) {
                $variance = round((float) $item->variance_quantity, 3);
                if (abs($variance) < 0.0005) {
                    continue;
                }
                $traceAllocations = $item->inventory_lot_id
                    ? [['inventory_lot_id' => $item->inventory_lot_id, 'quantity' => abs($variance)]]
                    : [];
                $movement = $this->movements->store([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $session->warehouse_id,
                    'location_id' => $item->location_id,
                    'type' => $variance > 0 ? 'in' : 'out',
                    'quantity' => abs($variance),
                    'stock_state' => $item->stock_state,
                    'movement_code' => 'stock_count',
                    'source_type' => 'inventory_count',
                    'source_id' => $session->id,
                    'reason' => "Approved {$session->count_number}: {$reason}",
                    'idempotency_key' => "inventory-count-{$session->id}-item-{$item->id}",
                    'trace_allocations' => $traceAllocations,
                    'metadata' => [
                        'snapshot_captured_at' => $session->frozen_at?->toIso8601String(),
                        'expected_quantity' => (float) $item->expected_quantity,
                        'counted_quantity' => (float) $item->counted_quantity,
                        'variance_quantity' => $variance,
                    ],
                ]);
                $item->update(['adjustment_movement_id' => $movement->id]);
            }
            $session->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
                'approval_reason' => $reason,
            ]);
            $this->events->record('inventory.count_approved', $session, $session->count_number, [
                'warehouse_id' => $session->warehouse_id, 'location_id' => $session->location_id,
                'item_count' => $items->count(),
            ], "inventory-count:{$session->id}:approved");

            return $this->find($session->fresh());
        });
    }

    public function cancel(InventoryCountSession $session, string $reason): InventoryCountSession
    {
        return DB::transaction(function () use ($session, $reason): InventoryCountSession {
            $session = InventoryCountSession::query()->lockForUpdate()->findOrFail($session->id);
            if (in_array($session->status, ['approved', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => ['An approved or cancelled count cannot be cancelled.']]);
            }
            $session->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => Auth::id(),
                'cancellation_reason' => $reason,
            ]);

            return $this->find($session->fresh());
        });
    }

    private function unexpectedItem(InventoryCountSession $session, array $input): InventoryCountItem
    {
        $product = Product::query()->findOrFail($input['product_id'] ?? 0);
        if (! $session->scope_all_products) {
            $allowedProductIds = collect($session->scope_product_ids
                ?: $session->items()->pluck('product_id'))
                ->map(fn ($id) => (int) $id)
                ->unique();
            if (! $allowedProductIds->contains((int) $product->id)) {
                throw ValidationException::withMessages([
                    'items' => ['The unexpected product is outside this count scope.'],
                ]);
            }
        }
        $locationId = array_key_exists('location_id', $input)
            ? ($input['location_id'] !== null ? (int) $input['location_id'] : null)
            : $session->location_id;
        if ($session->location_id !== null && (int) $locationId !== (int) $session->location_id) {
            throw ValidationException::withMessages(['items' => ['An unexpected item must be in this count location.']]);
        }
        if ($locationId !== null && ! WarehouseLocation::query()
            ->whereKey($locationId)->where('warehouse_id', $session->warehouse_id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['items' => ['The unexpected item location is not in the count warehouse.']]);
        }
        $state = $input['stock_state'] ?? 'available';
        if (! in_array($state, $session->stock_states ?: ['available'], true)) {
            throw ValidationException::withMessages(['items' => ['The stock state is outside this count scope.']]);
        }

        $lotId = null;
        if (($product->tracking_mode ?: 'none') !== 'none') {
            if (! empty($input['inventory_lot_id'])) {
                $lotId = InventoryLot::withoutGlobalScopes()
                    ->where('company_id', $session->company_id)
                    ->where('product_id', $product->id)
                    ->whereKey((int) $input['inventory_lot_id'])
                    ->value('id');
                if (! $lotId) {
                    throw ValidationException::withMessages([
                        'items' => ['The selected lot or serial does not belong to this company and product.'],
                    ]);
                }
            } else {
                $lotId = $this->traceability->resolveIdentityForCount($product, $input['trace'] ?? $input)->id;
            }
        } elseif (! empty($input['inventory_lot_id'])) {
            throw ValidationException::withMessages([
                'items' => ['An untracked product cannot reference a lot or serial identity.'],
            ]);
        }

        return $this->createItem($session, $product, $locationId, $state, 0, $lotId);
    }

    private function createItem(
        InventoryCountSession $session,
        Product $product,
        ?int $locationId,
        string $state,
        float $expected,
        ?int $lotId = null,
    ): InventoryCountItem {
        return InventoryCountItem::withoutGlobalScopes()->firstOrCreate(
            [
                'inventory_count_session_id' => $session->id,
                'product_id' => $product->id,
                'location_key' => (int) ($locationId ?? 0),
                'lot_key' => (int) ($lotId ?? 0),
                'stock_state' => $state,
            ],
            [
                'company_id' => $session->company_id,
                'location_id' => $locationId,
                'inventory_lot_id' => $lotId,
                'expected_quantity' => round($expected, 3),
                'count_round' => 1,
                'requires_recount' => false,
            ],
        );
    }

    /**
     * Rebuild the authoritative identities and book quantities inside a
     * persisted count scope. Product locks are acquired by the caller before
     * these warehouse and trace rows are locked.
     *
     * @param  Collection<int, Product>  $products
     * @param  array<int, int>|null  $scopeProductIds
     * @return Collection<string, array{product: Product, location_id: int|null, inventory_lot_id: int|null, stock_state: string, expected_quantity: float}>
     */
    private function snapshotDefinitions(
        InventoryCountSession $session,
        Collection $products,
        ?array $scopeProductIds,
    ): Collection {
        $states = collect($session->stock_states ?: ['available'])
            ->map(fn ($state) => (string) $state)->values();
        $productMap = $products->keyBy('id');
        $stockRows = WarehouseStock::withoutGlobalScopes()
            ->where('company_id', $session->company_id)
            ->where('warehouse_id', $session->warehouse_id)
            ->when($session->location_id !== null, fn ($query) => $query->where('location_key', (int) $session->location_id))
            ->when($scopeProductIds !== null, fn ($query) => $query->whereIn('product_id', $scopeProductIds))
            ->orderBy('product_id')->orderBy('location_key')->lockForUpdate()->get();
        $traceRows = InventoryTraceBalance::withoutGlobalScopes()
            ->select('inventory_trace_balances.*', 'inventory_lots.product_id')
            ->join('inventory_lots', 'inventory_lots.id', '=', 'inventory_trace_balances.inventory_lot_id')
            ->where('inventory_trace_balances.company_id', $session->company_id)
            ->where('inventory_trace_balances.warehouse_id', $session->warehouse_id)
            ->whereIn('inventory_trace_balances.stock_state', $states)
            ->when($session->location_id !== null, fn ($query) => $query->where('inventory_trace_balances.location_key', (int) $session->location_id))
            ->when($scopeProductIds !== null, fn ($query) => $query->whereIn('inventory_lots.product_id', $scopeProductIds))
            ->orderBy('inventory_lots.product_id')
            ->orderBy('inventory_trace_balances.location_key')
            ->orderBy('inventory_trace_balances.inventory_lot_id')
            ->lockForUpdate()->get();

        $definitions = collect();
        $add = function (
            Product $product,
            ?int $locationId,
            int $locationKey,
            ?int $lotId,
            string $state,
            float $expected,
        ) use ($definitions): void {
            $key = $this->identityKey($product->id, $locationKey, (int) ($lotId ?? 0), $state);
            $definitions->put($key, [
                'product' => $product,
                'location_id' => $locationId,
                'inventory_lot_id' => $lotId,
                'stock_state' => $state,
                'expected_quantity' => round($expected, 3),
            ]);
        };

        foreach ($stockRows as $stock) {
            $product = $productMap->get((int) $stock->product_id);
            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => ['A warehouse balance references a product outside this count scope.'],
                ]);
            }
            foreach ($states as $state) {
                $expected = round((float) $stock->{$state.'_quantity'}, 3);
                if (($product->tracking_mode ?: 'none') === 'none') {
                    $add(
                        $product,
                        $stock->location_id ? (int) $stock->location_id : null,
                        (int) $stock->location_key,
                        null,
                        $state,
                        $expected,
                    );
                    continue;
                }

                $matchingTrace = $traceRows->filter(fn (InventoryTraceBalance $trace) =>
                    (int) $trace->product_id === (int) $product->id
                    && (int) $trace->location_key === (int) $stock->location_key
                    && (string) $trace->stock_state === $state
                );
                if (abs(round((float) $matchingTrace->sum('quantity'), 3) - $expected) >= 0.0005) {
                    throw ValidationException::withMessages([
                        'items' => ["Tracked balances for {$product->name} no longer reconcile with its bin balance."],
                    ]);
                }
                foreach ($matchingTrace as $trace) {
                    $add(
                        $product,
                        $trace->location_id ? (int) $trace->location_id : null,
                        (int) $trace->location_key,
                        (int) $trace->inventory_lot_id,
                        $state,
                        (float) $trace->quantity,
                    );
                }
            }
        }

        if ($scopeProductIds !== null) {
            foreach ($products as $product) {
                if (($product->tracking_mode ?: 'none') !== 'none'
                    || $definitions->contains(fn (array $row) => (int) $row['product']->id === (int) $product->id)) {
                    continue;
                }
                foreach ($states as $state) {
                    $add(
                        $product,
                        $session->location_id ? (int) $session->location_id : null,
                        (int) ($session->location_id ?? 0),
                        null,
                        $state,
                        0,
                    );
                }
            }
        }

        return $definitions;
    }

    private function currentBalance(
        InventoryCountSession $session,
        InventoryCountItem $item,
        bool $lock = false,
    ): float {
        if ($item->inventory_lot_id) {
            $query = InventoryTraceBalance::withoutGlobalScopes()
                ->where('company_id', $session->company_id)
                ->where('inventory_lot_id', $item->inventory_lot_id)
                ->where('warehouse_id', $session->warehouse_id)
                ->where('location_key', (int) ($item->location_id ?? 0))
                ->where('stock_state', $item->stock_state);
        } else {
            $query = WarehouseStock::withoutGlobalScopes()
                ->where('company_id', $session->company_id)
                ->where('product_id', $item->product_id)
                ->where('warehouse_id', $session->warehouse_id)
                ->where('location_key', (int) ($item->location_id ?? 0));
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        if ($item->inventory_lot_id) {
            return round((float) ($query->value('quantity') ?? 0), 3);
        }

        return round((float) ($query->value($item->stock_state.'_quantity') ?? 0), 3);
    }

    /**
     * A quantity comparison alone misses a new bin, lot, serial, or product
     * introduced inside the original count scope. Rebuild the complete set of
     * physical identities before approval and compare it with the immutable
     * creation snapshot. Unexpected items counted by the user do not alter the
     * original snapshot set.
     *
     * @param  \Illuminate\Support\Collection<int, InventoryCountItem>  $items
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @param  array<int, int>|null  $scopeProductIds
     */
    private function assertSnapshotScopeUnchanged(
        InventoryCountSession $session,
        $items,
        $products,
        ?array $scopeProductIds,
    ): void {
        $states = collect($session->stock_states ?: ['available'])->map(fn ($state) => (string) $state)->values();
        $stockRows = WarehouseStock::withoutGlobalScopes()
            ->where('company_id', $session->company_id)
            ->where('warehouse_id', $session->warehouse_id)
            ->when($session->location_id !== null, fn ($query) => $query->where('location_key', (int) $session->location_id))
            ->when($scopeProductIds !== null, fn ($query) => $query->whereIn('product_id', $scopeProductIds))
            ->orderBy('product_id')->orderBy('location_key')->lockForUpdate()->get();
        $traceRows = InventoryTraceBalance::withoutGlobalScopes()
            ->select('inventory_trace_balances.*', 'inventory_lots.product_id')
            ->join('inventory_lots', 'inventory_lots.id', '=', 'inventory_trace_balances.inventory_lot_id')
            ->where('inventory_trace_balances.company_id', $session->company_id)
            ->where('inventory_trace_balances.warehouse_id', $session->warehouse_id)
            ->whereIn('inventory_trace_balances.stock_state', $states)
            ->when($session->location_id !== null, fn ($query) => $query->where('inventory_trace_balances.location_key', (int) $session->location_id))
            ->when($scopeProductIds !== null, fn ($query) => $query->whereIn('inventory_lots.product_id', $scopeProductIds))
            ->orderBy('inventory_lots.product_id')
            ->orderBy('inventory_trace_balances.location_key')
            ->orderBy('inventory_trace_balances.inventory_lot_id')
            ->lockForUpdate()->get();

        $productMap = $products->keyBy('id');
        $currentKeys = collect();
        foreach ($stockRows as $stock) {
            $product = $productMap->get((int) $stock->product_id);
            if (! $product || ($product->tracking_mode ?: 'none') !== 'none') {
                continue;
            }
            foreach ($states as $state) {
                $currentKeys->push($this->identityKey(
                    (int) $stock->product_id,
                    (int) $stock->location_key,
                    0,
                    $state,
                ));
            }
        }
        foreach ($traceRows as $trace) {
            $currentKeys->push($this->identityKey(
                (int) $trace->product_id,
                (int) $trace->location_key,
                (int) $trace->inventory_lot_id,
                (string) $trace->stock_state,
            ));
        }

        // Explicit zero-stock untracked products receive a zero identity when
        // the snapshot is created, so reproduce it when no balance exists.
        if ($scopeProductIds !== null) {
            foreach ($products as $product) {
                if (($product->tracking_mode ?: 'none') !== 'none'
                    || $stockRows->contains(fn (WarehouseStock $row) => (int) $row->product_id === (int) $product->id)) {
                    continue;
                }
                foreach ($states as $state) {
                    $currentKeys->push($this->identityKey(
                        (int) $product->id,
                        (int) ($session->location_id ?? 0),
                        0,
                        $state,
                    ));
                }
            }
        }

        $expectedKeys = collect($session->snapshot_identity_keys
            ?: $items->map(fn (InventoryCountItem $item) => $this->identityKey(
                (int) $item->product_id,
                (int) $item->location_key,
                (int) $item->lot_key,
                (string) $item->stock_state,
            )))->unique()->sort()->values()->all();
        // A recount retains removed identities as explicit zero book rows so
        // the counter can confirm that the physical quantity is zero without
        // deleting prior count-entry history. Their absence from live balance
        // tables is therefore the expected state, not another scope change.
        foreach ($items as $item) {
            if (abs((float) $item->expected_quantity) >= 0.0005) {
                continue;
            }
            $key = $this->identityKey(
                (int) $item->product_id,
                (int) $item->location_key,
                (int) $item->lot_key,
                (string) $item->stock_state,
            );
            if (in_array($key, $expectedKeys, true) && ! $currentKeys->contains($key)) {
                $currentKeys->push($key);
            }
        }
        $currentKeys = $currentKeys->unique()->sort()->values()->all();
        if ($expectedKeys !== $currentKeys) {
            throw ValidationException::withMessages([
                'items' => [
                    "Inventory identities inside {$session->count_number} changed after its snapshot was captured. "
                    .'A product, bin, lot, serial, or stock-state row was added or removed; request a recount before approval.',
                ],
            ]);
        }
    }

    private function identityKey(int $productId, int $locationKey, int $lotKey, string $state): string
    {
        return "product:{$productId}/location:{$locationKey}/lot:{$lotKey}/state:{$state}";
    }

    private function nextNumber(int $companyId): string
    {
        $prefix = 'CNT-'.now('Europe/Tirane')->format('Y').'-';
        $sequence = InventoryCountSession::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('count_number', 'like', $prefix.'%')->count() + 1;
        do {
            $number = $prefix.str_pad((string) $sequence++, 5, '0', STR_PAD_LEFT);
        } while (InventoryCountSession::withoutGlobalScopes()->where('company_id', $companyId)->where('count_number', $number)->exists());

        return $number;
    }
}
