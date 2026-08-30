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
            foreach ($states as $state) {
                if (! in_array($state, WarehouseInventoryService::STATES, true)) {
                    throw ValidationException::withMessages(['stock_states' => ['One or more stock states are invalid.']]);
                }
            }

            $session = InventoryCountSession::create([
                'company_id' => $companyId,
                'count_number' => $this->nextNumber($companyId),
                'warehouse_id' => $warehouse->id,
                'location_id' => $locationId,
                'status' => 'in_progress',
                'stock_states' => $states,
                'frozen_at' => now(),
                'created_by' => Auth::id(),
                'notes' => $data['notes'] ?? null,
            ]);

            $stockRows = WarehouseStock::withoutGlobalScopes()
                ->with('product')
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouse->id)
                ->when($locationId !== null, fn ($query) => $query->where('location_key', $locationId))
                ->when(! empty($data['product_ids']), fn ($query) => $query->whereIn('product_id', $data['product_ids']))
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

            foreach (array_unique($data['product_ids'] ?? []) as $productId) {
                $product = Product::query()->findOrFail($productId);
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
            $items = $session->items()->whereIn('id', $itemIds)->lockForUpdate()->get();
            if ($items->count() !== count(array_unique($itemIds))) {
                throw ValidationException::withMessages(['item_ids' => ['One or more count items do not belong to this session.']]);
            }
            foreach ($items as $item) {
                $currentExpected = $this->currentBalance($session, $item, true);
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
            $session->update(['status' => 'recount_required', 'submitted_at' => null, 'submitted_by' => null]);

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
            foreach ($items as $item) {
                $current = $this->currentBalance($session, $item, true);
                if (abs($current - (float) $item->expected_quantity) >= 0.0005) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Inventory changed after {$session->count_number} froze {$item->product->name}. "
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
                        'frozen_at' => $session->frozen_at?->toIso8601String(),
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
