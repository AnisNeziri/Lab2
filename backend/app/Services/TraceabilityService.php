<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\InventoryTraceBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockMovementTrace;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TraceabilityService
{
    public const MODES = ['none', 'batch', 'serial', 'batch_expiry'];

    /** Outbound operations that represent normal fulfilment/picking. */
    private const EXPIRY_BLOCKED_MOVEMENTS = [
        'daily_sale', 'invoice_sale', 'transfer_out', 'bin_transfer_out', 'warehouse_pick',
    ];

    /** Normal supplier receipts; opening/import/reconciliation history remains importable. */
    private const EXPIRED_RECEIPT_BLOCKED_MOVEMENTS = ['purchase_receipt', 'damage_received'];

    /**
     * Apply the trace side of an already validated aggregate stock movement.
     * This must be called inside the same DB transaction as the movement and
     * warehouse balance mutation.
     */
    public function applyMovement(
        StockMovement $movement,
        Product $product,
        Warehouse $warehouse,
        ?int $locationId,
        string $stockState,
        array $allocations = [],
        bool $allowExpiredOverride = false,
        bool $allowExpiredReceiptOverride = false,
    ): Collection {
        $mode = $product->tracking_mode ?: 'none';
        if (! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages(['tracking_mode' => ['The product tracking mode is invalid.']]);
        }
        if ($mode === 'none') {
            if ($allocations !== []) {
                throw ValidationException::withMessages(['trace_allocations' => ['This product does not use lot or serial tracking.']]);
            }

            return new Collection();
        }

        $requestedQuantity = round((float) $movement->quantity, 3);
        $blocksExpiredStock = ! $allowExpiredOverride && $movement->type === 'out'
            && in_array($movement->movement_code, self::EXPIRY_BLOCKED_MOVEMENTS, true);
        $blocksExpiredReceipt = ! $allowExpiredReceiptOverride && $movement->type === 'in'
            && in_array($movement->movement_code, self::EXPIRED_RECEIPT_BLOCKED_MOVEMENTS, true);
        if ($allocations === [] && $movement->type === 'out' && $mode !== 'serial' && $product->fefo_enabled) {
            $allocations = $this->fefoAllocations(
                $product,
                $warehouse,
                $locationId,
                $stockState,
                $requestedQuantity,
                ! $blocksExpiredStock,
            );
        }
        if ($allocations === []) {
            $message = $mode === 'serial'
                ? 'Select every serial number for this stock movement.'
                : 'Enter the lot or batch allocation for this stock movement.';
            throw ValidationException::withMessages(['trace_allocations' => [$message]]);
        }

        $resolved = collect($allocations)->map(function (array $allocation) use ($product, $movement, $blocksExpiredStock, $blocksExpiredReceipt): array {
            $quantity = round((float) ($allocation['quantity'] ?? 0), 3);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['trace_allocations' => ['Every trace allocation quantity must be greater than zero.']]);
            }
            $lot = $movement->type === 'in'
                ? $this->resolveInboundIdentity($product, $allocation, true, $movement->occurred_at)
                : $this->resolveExistingIdentity($product, $allocation);

            if ($blocksExpiredStock && $lot->expiry_at && $lot->expiry_at->lt(now()->startOfDay())) {
                throw ValidationException::withMessages([
                    'trace_allocations' => [
                        "Expired lot {$this->displayIdentity($lot)} cannot be sold, picked, or transferred as normal stock.",
                    ],
                ]);
            }

            $receiptDate = ($movement->occurred_at ?: now())->copy()->startOfDay();
            if ($blocksExpiredReceipt && $lot->expiry_at && $lot->expiry_at->lt($receiptDate)) {
                throw ValidationException::withMessages([
                    'trace_allocations' => [
                        "Expired lot {$this->displayIdentity($lot)} cannot be received as normal stock without an authorized reason.",
                    ],
                ]);
            }

            if (($product->tracking_mode ?: 'none') === 'serial' && abs($quantity - 1.0) >= 0.0005) {
                throw ValidationException::withMessages(['trace_allocations' => ['Each serial number must represent exactly one base unit.']]);
            }

            return ['lot' => $lot, 'quantity' => $quantity];
        })->groupBy(fn (array $row) => $row['lot']->id)
            ->map(fn ($rows) => [
                'lot' => $rows->first()['lot'],
                'quantity' => round((float) $rows->sum('quantity'), 3),
            ])->values();

        $allocated = round((float) $resolved->sum('quantity'), 3);
        if (abs($allocated - $requestedQuantity) >= 0.0005) {
            throw ValidationException::withMessages([
                'trace_allocations' => ["Trace allocations total {$allocated}; the movement quantity is {$requestedQuantity}."],
            ]);
        }

        $lines = new Collection();
        foreach ($resolved as $allocation) {
            /** @var InventoryLot $lot */
            $lot = $allocation['lot'];
            $quantity = $allocation['quantity'];

            if ($movement->type === 'in' && $product->tracking_mode === 'serial') {
                $activeQuantity = (float) InventoryTraceBalance::withoutGlobalScopes()
                    ->where('inventory_lot_id', $lot->id)
                    ->sum('quantity');
                if ($activeQuantity >= 0.0005) {
                    throw ValidationException::withMessages([
                        'trace_allocations' => ["Serial number {$lot->serial_number} is already active in inventory."],
                    ]);
                }
            }

            $balance = InventoryTraceBalance::withoutGlobalScopes()->firstOrCreate(
                [
                    'inventory_lot_id' => $lot->id,
                    'warehouse_id' => $warehouse->id,
                    'location_key' => (int) ($locationId ?? 0),
                    'stock_state' => $stockState,
                ],
                [
                    'company_id' => $product->company_id,
                    'location_id' => $locationId,
                    'quantity' => 0,
                ],
            );
            $balance = InventoryTraceBalance::withoutGlobalScopes()->lockForUpdate()->findOrFail($balance->id);
            $before = round((float) $balance->quantity, 3);
            if ($movement->type === 'out' && $before + 0.0005 < $quantity) {
                throw ValidationException::withMessages([
                    'trace_allocations' => ["Insufficient stock for trace identity {$this->displayIdentity($lot)} at the selected location."],
                ]);
            }
            $balance->quantity = round($movement->type === 'in' ? $before + $quantity : $before - $quantity, 3);
            $balance->save();

            $line = StockMovementTrace::withoutGlobalScopes()->create([
                'company_id' => $product->company_id,
                'stock_movement_id' => $movement->id,
                'inventory_lot_id' => $lot->id,
                'quantity' => $quantity,
            ]);
            $lines->push($line->load('lot.product'));

            $remaining = (float) InventoryTraceBalance::withoutGlobalScopes()
                ->where('inventory_lot_id', $lot->id)
                ->sum('quantity');
            $lot->update(['status' => $remaining >= 0.0005 ? 'active' : 'depleted']);
        }

        return $lines;
    }

    /**
     * Produce, but do not apply, a transparent FEFO allocation plan.
     */
    public function fefoAllocations(
        Product $product,
        Warehouse $warehouse,
        ?int $locationId,
        string $stockState,
        float $quantity,
        bool $allowExpired = false,
    ): array {
        $remaining = round($quantity, 3);
        $balances = InventoryTraceBalance::withoutGlobalScopes()
            ->select('inventory_trace_balances.*')
            ->join('inventory_lots', 'inventory_lots.id', '=', 'inventory_trace_balances.inventory_lot_id')
            ->where('inventory_trace_balances.company_id', $product->company_id)
            ->where('inventory_lots.product_id', $product->id)
            ->where('inventory_trace_balances.warehouse_id', $warehouse->id)
            ->where('inventory_trace_balances.location_key', (int) ($locationId ?? 0))
            ->where('inventory_trace_balances.stock_state', $stockState)
            ->where('inventory_trace_balances.quantity', '>', 0)
            ->when(! $allowExpired, fn ($query) => $query->where(function ($lotQuery): void {
                $lotQuery->whereNull('inventory_lots.expiry_at')
                    ->orWhereDate('inventory_lots.expiry_at', '>=', now()->toDateString());
            }))
            ->orderByRaw('CASE WHEN inventory_lots.expiry_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('inventory_lots.expiry_at')
            ->orderBy('inventory_lots.created_at')
            ->orderBy('inventory_lots.id')
            ->lockForUpdate()
            ->get();

        $allocations = [];
        foreach ($balances as $balance) {
            if ($remaining < 0.0005) {
                break;
            }
            $take = round(min($remaining, (float) $balance->quantity), 3);
            if ($take > 0) {
                $allocations[] = ['inventory_lot_id' => $balance->inventory_lot_id, 'quantity' => $take];
                $remaining = round($remaining - $take, 3);
            }
        }
        if ($remaining >= 0.0005) {
            throw ValidationException::withMessages([
                'trace_allocations' => ['Tracked stock at the selected warehouse/bin is insufficient for this movement.'],
            ]);
        }

        return $allocations;
    }

    /**
     * Allocate a tracked outbound operation across all eligible bins while
     * keeping each resulting movement location-specific. Automatic plans are
     * globally FEFO ordered; explicit lot/serial selections are preserved and
     * resolved to the bins that actually hold those identities.
     *
     * @return array<int, array{location_id: int|null, quantity: float, trace_allocations: array<int, array{inventory_lot_id: int, quantity: float}>}>
     */
    public function outboundLocationAllocations(
        Product $product,
        Warehouse $warehouse,
        ?int $locationId,
        string $stockState,
        float $quantity,
        array $allocations = [],
        bool $allowExpired = false,
    ): array {
        if (! in_array($stockState, WarehouseInventoryService::STATES, true)) {
            throw ValidationException::withMessages(['stock_state' => ['The selected inventory state is invalid.']]);
        }

        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Quantity must be greater than zero.']]);
        }

        $mode = $product->tracking_mode ?: 'none';
        if ($allocations === []) {
            if ($mode === 'serial') {
                throw ValidationException::withMessages(['trace_allocations' => ['Select every serial number for this stock movement.']]);
            }
            if (! $product->fefo_enabled) {
                throw ValidationException::withMessages(['trace_allocations' => ['Enter the lot or batch allocation for this stock movement.']]);
            }
        }

        $stateColumn = $stockState.'_quantity';
        $warehouseBalances = WarehouseStock::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->when($locationId !== null, fn ($query) => $query->where('location_key', $locationId))
            ->where($stateColumn, '>', 0)
            ->orderBy('location_key')->orderBy('id')
            ->lockForUpdate()->get();
        $capacity = $warehouseBalances->mapWithKeys(fn (WarehouseStock $balance) => [
            (int) $balance->location_key => round((float) $balance->{$stateColumn}, 3),
        ])->all();

        if (round((float) array_sum($capacity), 3) + 0.0005 < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => ['Tracked stock across the eligible bins is insufficient for this movement.'],
            ]);
        }

        $requests = null;
        $lotIds = [];
        if ($allocations !== []) {
            $requests = collect($allocations)->map(function (array $allocation) use ($product): array {
                $requested = round((float) ($allocation['quantity'] ?? 0), 3);
                if ($requested <= 0) {
                    throw ValidationException::withMessages(['trace_allocations' => ['Every trace allocation quantity must be greater than zero.']]);
                }
                $lot = $this->resolveExistingIdentity($product, $allocation);

                return [
                    'inventory_lot_id' => (int) $lot->id,
                    'quantity' => $requested,
                    // Internal callers (mobile picking) may pin a selected
                    // lot/serial to the scanned bin.
                    'location_id' => array_key_exists('location_id', $allocation)
                        ? ($allocation['location_id'] === null ? null : (int) $allocation['location_id'])
                        : null,
                    'location_is_explicit' => array_key_exists('location_id', $allocation),
                ];
            })->values();
            $allocated = round((float) $requests->sum('quantity'), 3);
            if (abs($allocated - $quantity) >= 0.0005) {
                throw ValidationException::withMessages([
                    'trace_allocations' => ["Trace allocations total {$allocated}; the movement quantity is {$quantity}."],
                ]);
            }
            $lotIds = $requests->pluck('inventory_lot_id')->unique()->all();
        }

        $traceBalances = InventoryTraceBalance::withoutGlobalScopes()
            ->select('inventory_trace_balances.*')
            ->join('inventory_lots', 'inventory_lots.id', '=', 'inventory_trace_balances.inventory_lot_id')
            ->where('inventory_trace_balances.company_id', $product->company_id)
            ->where('inventory_lots.product_id', $product->id)
            ->where('inventory_trace_balances.warehouse_id', $warehouse->id)
            ->when($locationId !== null, fn ($query) => $query->where('inventory_trace_balances.location_key', $locationId))
            ->where('inventory_trace_balances.stock_state', $stockState)
            ->where('inventory_trace_balances.quantity', '>', 0)
            ->when($lotIds !== [], fn ($query) => $query->whereIn('inventory_trace_balances.inventory_lot_id', $lotIds))
            ->when($allocations === [] && ! $allowExpired, fn ($query) => $query->where(function ($lotQuery): void {
                $lotQuery->whereNull('inventory_lots.expiry_at')
                    ->orWhereDate('inventory_lots.expiry_at', '>=', now()->toDateString());
            }))
            ->orderByRaw('CASE WHEN inventory_lots.expiry_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('inventory_lots.expiry_at')
            ->orderBy('inventory_lots.created_at')
            ->orderBy('inventory_lots.id')
            ->orderBy('inventory_trace_balances.location_key')
            ->orderBy('inventory_trace_balances.id')
            ->lockForUpdate()->get();

        $plans = [];
        $traceCapacity = $traceBalances->mapWithKeys(fn (InventoryTraceBalance $balance) => [
            (int) $balance->id => round((float) $balance->quantity, 3),
        ])->all();
        $append = function (InventoryTraceBalance $balance, float $take) use (&$plans, &$capacity, &$traceCapacity): void {
            $locationKey = (int) $balance->location_key;
            $key = (string) $locationKey;
            $plans[$key] ??= [
                'location_id' => $balance->location_id ? (int) $balance->location_id : null,
                'quantity' => 0.0,
                'trace_allocations' => [],
            ];
            $plans[$key]['quantity'] = round($plans[$key]['quantity'] + $take, 3);
            $lotId = (int) $balance->inventory_lot_id;
            $plans[$key]['trace_allocations'][$lotId] ??= ['inventory_lot_id' => $lotId, 'quantity' => 0.0];
            $plans[$key]['trace_allocations'][$lotId]['quantity'] = round(
                $plans[$key]['trace_allocations'][$lotId]['quantity'] + $take,
                3,
            );
            $capacity[$locationKey] = round(($capacity[$locationKey] ?? 0) - $take, 3);
            $traceCapacity[(int) $balance->id] = round(($traceCapacity[(int) $balance->id] ?? 0) - $take, 3);
        };

        if ($requests !== null) {
            foreach ($requests as $request) {
                $remaining = (float) $request['quantity'];
                $candidates = $traceBalances
                    ->where('inventory_lot_id', $request['inventory_lot_id'])
                    ->when($request['location_is_explicit'], fn ($rows) => $rows->where(
                        'location_key',
                        (int) ($request['location_id'] ?? 0),
                    ));
                foreach ($candidates as $balance) {
                    if ($remaining < 0.0005) {
                        break;
                    }
                    $locationCapacity = max(0, (float) ($capacity[(int) $balance->location_key] ?? 0));
                    $lotCapacity = max(0, (float) ($traceCapacity[(int) $balance->id] ?? 0));
                    $take = round(min($remaining, $lotCapacity, $locationCapacity), 3);
                    if ($take <= 0) {
                        continue;
                    }
                    $append($balance, $take);
                    $remaining = round($remaining - $take, 3);
                }
                if ($remaining >= 0.0005) {
                    throw ValidationException::withMessages([
                        'trace_allocations' => ['A selected lot or serial does not have enough stock in the eligible bins.'],
                    ]);
                }
            }
        } else {
            $remaining = $quantity;
            foreach ($traceBalances as $balance) {
                if ($remaining < 0.0005) {
                    break;
                }
                $locationCapacity = max(0, (float) ($capacity[(int) $balance->location_key] ?? 0));
                $lotCapacity = max(0, (float) ($traceCapacity[(int) $balance->id] ?? 0));
                $take = round(min($remaining, $lotCapacity, $locationCapacity), 3);
                if ($take <= 0) {
                    continue;
                }
                $append($balance, $take);
                $remaining = round($remaining - $take, 3);
            }
            if ($remaining >= 0.0005) {
                throw ValidationException::withMessages([
                    'trace_allocations' => ['Valid tracked stock across the eligible bins is insufficient for this movement.'],
                ]);
            }
        }

        return collect($plans)->map(function (array $plan): array {
            $plan['trace_allocations'] = array_values($plan['trace_allocations']);

            return $plan;
        })->values()->all();
    }

    public function expiring(Product $product, ?int $warehouseId = null, ?int $withinDays = null): Collection
    {
        $days = $withinDays ?? (int) ($product->near_expiry_days ?: 30);

        return InventoryLot::query()
            ->with(['product:id,name,sku,tracking_mode,expiration_controlled,near_expiry_days', 'balances' => fn ($query) => $query
                ->with(['warehouse:id,name,code', 'location:id,name,code,path'])
                ->where('quantity', '>', 0)
                ->when($warehouseId, fn ($nested) => $nested->where('warehouse_id', $warehouseId))])
            ->withSum(['balances as active_quantity' => fn ($query) => $query
                ->where('quantity', '>', 0)
                ->when($warehouseId, fn ($nested) => $nested->where('warehouse_id', $warehouseId))], 'quantity')
            ->where('product_id', $product->id)
            ->whereNotNull('expiry_at')
            ->whereDate('expiry_at', '<=', now()->addDays($days)->toDateString())
            ->whereHas('balances', fn ($query) => $query
                ->where('quantity', '>', 0)
                ->when($warehouseId, fn ($nested) => $nested->where('warehouse_id', $warehouseId)))
            ->orderBy('expiry_at')
            ->get();
    }

    public function resolveIdentityForCount(Product $product, array $trace): InventoryLot
    {
        if (($product->tracking_mode ?: 'none') === 'none') {
            throw ValidationException::withMessages(['trace' => ['An untracked product cannot have a lot or serial count item.']]);
        }

        return $this->resolveInboundIdentity($product, $trace, false);
    }

    private function resolveInboundIdentity(
        Product $product,
        array $allocation,
        bool $validateActiveSerial = true,
        ?Carbon $receiptDate = null,
    ): InventoryLot
    {
        if (! empty($allocation['inventory_lot_id'])) {
            return $this->resolveExistingIdentity($product, $allocation);
        }

        $mode = $product->tracking_mode ?: 'none';
        $lotNumber = $this->nullableTrim($allocation['lot_number'] ?? null);
        $serialNumber = $this->nullableTrim($allocation['serial_number'] ?? null);
        $expiryAt = $this->nullableDate($allocation['expiry_at'] ?? $allocation['expiry_date'] ?? null);
        $manufacturedAt = $this->nullableDate($allocation['manufactured_at'] ?? $allocation['manufacturing_date'] ?? null);
        $expirationControlled = (bool) $product->expiration_controlled || $mode === 'batch_expiry';
        if ($expirationControlled && ! $expiryAt && (int) $product->default_shelf_life_days > 0) {
            $basis = $product->shelf_life_basis ?: 'manufacture_date';
            if ($basis === 'manufacture_date' && ! $manufacturedAt) {
                throw ValidationException::withMessages([
                    'trace_allocations' => ['Enter a manufacture date to derive expiry, or enter an explicit expiry date.'],
                ]);
            }
            if ($basis === 'receipt_date' && ! $receiptDate) {
                throw ValidationException::withMessages([
                    'trace_allocations' => ['Enter an explicit expiry date because this operation has no receipt date.'],
                ]);
            }
            $expiryAt = ($basis === 'receipt_date' ? $receiptDate : $manufacturedAt)
                ->copy()->startOfDay()->addDays((int) $product->default_shelf_life_days);
        }
        if ($mode === 'serial' && ! $serialNumber) {
            throw ValidationException::withMessages(['trace_allocations' => ['A serial number is required for this product.']]);
        }
        if (in_array($mode, ['batch', 'batch_expiry'], true) && ! $lotNumber) {
            throw ValidationException::withMessages(['trace_allocations' => ['A lot/batch number is required for this product.']]);
        }
        if ($expirationControlled && ! $expiryAt) {
            throw ValidationException::withMessages(['trace_allocations' => ['An expiry date is required for this product.']]);
        }
        if ($manufacturedAt && $expiryAt && $expiryAt->lt($manufacturedAt)) {
            throw ValidationException::withMessages(['trace_allocations' => ['Expiry date cannot be before the manufacturing date.']]);
        }

        $identityMode = $expirationControlled && $mode === 'batch' ? 'batch_expiry' : $mode;
        $identityKey = $this->identityKey($identityMode, $lotNumber, $serialNumber, $expiryAt?->toDateString());
        $existing = InventoryLot::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('identity_key', $identityKey)
            ->first();
        if ($existing) {
            if ($mode === 'serial') {
                $this->assertSerialDatesPreserved($existing, $manufacturedAt, $expiryAt);
            }
            if ($validateActiveSerial && $mode === 'serial'
                && (float) InventoryTraceBalance::withoutGlobalScopes()->where('inventory_lot_id', $existing->id)->sum('quantity') >= 0.0005) {
                throw ValidationException::withMessages(['trace_allocations' => ["Serial number {$serialNumber} is already active in inventory."]]);
            }

            return $existing;
        }

        if ($mode === 'serial') {
            $serialExists = InventoryLot::withoutGlobalScopes()
                ->where('company_id', $product->company_id)
                ->whereRaw('LOWER(serial_number) = ?', [mb_strtolower($serialNumber)])
                ->exists();
            if ($serialExists) {
                throw ValidationException::withMessages(['trace_allocations' => ["Serial number {$serialNumber} already belongs to another inventory identity."]]);
            }
        }

        return InventoryLot::withoutGlobalScopes()->create([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'tracking_mode_snapshot' => $mode,
            'identity_key' => $identityKey,
            'lot_number' => $lotNumber,
            'serial_number' => $serialNumber,
            'supplier_batch' => $this->nullableTrim($allocation['supplier_batch'] ?? null),
            'manufactured_at' => $manufacturedAt?->toDateString(),
            'expiry_at' => $expiryAt?->toDateString(),
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);
    }

    private function resolveExistingIdentity(Product $product, array $allocation): InventoryLot
    {
        $query = InventoryLot::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id);
        if (! empty($allocation['inventory_lot_id'])) {
            $query->whereKey((int) $allocation['inventory_lot_id']);
        } else {
            $identityKey = $this->identityKey(
                ((bool) $product->expiration_controlled && $product->tracking_mode === 'batch')
                    ? 'batch_expiry'
                    : ($product->tracking_mode ?: 'none'),
                $this->nullableTrim($allocation['lot_number'] ?? null),
                $this->nullableTrim($allocation['serial_number'] ?? null),
                $this->nullableDate($allocation['expiry_at'] ?? $allocation['expiry_date'] ?? null)?->toDateString(),
            );
            $query->where('identity_key', $identityKey);
        }

        $lot = $query->first();
        if (! $lot) {
            throw ValidationException::withMessages(['trace_allocations' => ['The selected lot or serial identity does not exist for this product.']]);
        }

        return $lot;
    }

    private function identityKey(string $mode, ?string $lot, ?string $serial, ?string $expiry): string
    {
        return match ($mode) {
            'serial' => 'serial:'.mb_strtolower((string) $serial),
            'batch_expiry' => 'batch:'.mb_strtolower((string) $lot).'|expiry:'.$expiry,
            'batch' => 'batch:'.mb_strtolower((string) $lot),
            default => throw ValidationException::withMessages(['tracking_mode' => ['Tracking details cannot be used for an untracked product.']]),
        };
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function nullableDate(mixed $value): ?Carbon
    {
        return blank($value) ? null : Carbon::parse($value)->startOfDay();
    }

    private function assertSerialDatesPreserved(InventoryLot $lot, ?Carbon $manufacturedAt, ?Carbon $expiryAt): void
    {
        foreach ([
            'manufacture date' => [$lot->manufactured_at, $manufacturedAt],
            'expiry date' => [$lot->expiry_at, $expiryAt],
        ] as $label => [$stored, $submitted]) {
            if ($submitted && (! $stored || ! $stored->isSameDay($submitted))) {
                throw ValidationException::withMessages([
                    'trace_allocations' => ["The {$label} for serial {$lot->serial_number} cannot differ from its preserved history."],
                ]);
            }
        }
    }

    private function displayIdentity(InventoryLot $lot): string
    {
        return $lot->serial_number ?: ($lot->lot_number ?: '#'.$lot->id);
    }
}
