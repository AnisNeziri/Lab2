<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\InventoryTraceBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockMovementTrace;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TraceabilityService
{
    public const MODES = ['none', 'batch', 'serial', 'batch_expiry'];

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
        if ($allocations === [] && $movement->type === 'out' && $mode !== 'serial' && $product->fefo_enabled) {
            $allocations = $this->fefoAllocations(
                $product,
                $warehouse,
                $locationId,
                $stockState,
                $requestedQuantity,
            );
        }
        if ($allocations === []) {
            $message = $mode === 'serial'
                ? 'Select every serial number for this stock movement.'
                : 'Enter the lot or batch allocation for this stock movement.';
            throw ValidationException::withMessages(['trace_allocations' => [$message]]);
        }

        $resolved = collect($allocations)->map(function (array $allocation) use ($product, $movement): array {
            $quantity = round((float) ($allocation['quantity'] ?? 0), 3);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['trace_allocations' => ['Every trace allocation quantity must be greater than zero.']]);
            }
            $lot = $movement->type === 'in'
                ? $this->resolveInboundIdentity($product, $allocation)
                : $this->resolveExistingIdentity($product, $allocation);

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

    public function expiring(Product $product, ?int $warehouseId = null, ?int $withinDays = null): Collection
    {
        $days = $withinDays ?? (int) ($product->near_expiry_days ?: 30);

        return InventoryLot::query()
            ->with(['product:id,name,sku,tracking_mode,near_expiry_days', 'balances' => fn ($query) => $query
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

    private function resolveInboundIdentity(Product $product, array $allocation, bool $validateActiveSerial = true): InventoryLot
    {
        if (! empty($allocation['inventory_lot_id'])) {
            return $this->resolveExistingIdentity($product, $allocation);
        }

        $mode = $product->tracking_mode ?: 'none';
        $lotNumber = $this->nullableTrim($allocation['lot_number'] ?? null);
        $serialNumber = $this->nullableTrim($allocation['serial_number'] ?? null);
        $expiryAt = $this->nullableDate($allocation['expiry_at'] ?? $allocation['expiry_date'] ?? null);
        $manufacturedAt = $this->nullableDate($allocation['manufactured_at'] ?? $allocation['manufacturing_date'] ?? null);
        if ($mode === 'serial' && ! $serialNumber) {
            throw ValidationException::withMessages(['trace_allocations' => ['A serial number is required for this product.']]);
        }
        if (in_array($mode, ['batch', 'batch_expiry'], true) && ! $lotNumber) {
            throw ValidationException::withMessages(['trace_allocations' => ['A lot/batch number is required for this product.']]);
        }
        if ($mode === 'batch_expiry' && ! $expiryAt) {
            throw ValidationException::withMessages(['trace_allocations' => ['An expiry date is required for this product.']]);
        }
        if ($manufacturedAt && $expiryAt && $expiryAt->lt($manufacturedAt)) {
            throw ValidationException::withMessages(['trace_allocations' => ['Expiry date cannot be before the manufacturing date.']]);
        }

        $identityKey = $this->identityKey($mode, $lotNumber, $serialNumber, $expiryAt?->toDateString());
        $existing = InventoryLot::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('identity_key', $identityKey)
            ->first();
        if ($existing) {
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
                $product->tracking_mode ?: 'none',
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

    private function displayIdentity(InventoryLot $lot): string
    {
        return $lot->serial_number ?: ($lot->lot_number ?: '#'.$lot->id);
    }
}
