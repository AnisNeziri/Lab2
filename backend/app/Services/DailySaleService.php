<?php

namespace App\Services;

use App\Models\DailySale;
use App\Models\DailySaleItem;
use App\Models\DailySalesDay;
use App\Models\StockMovement;
use App\Models\Product;
use App\Support\Money;
use App\Support\RequestFingerprint;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DailySaleService
{
    public function __construct(
        private readonly StockMovementService $stockMovements,
        private readonly UnitConversionService $unitConversions,
        private readonly InventoryCostingService $costing,
    ) {}

    public function list(array $filters = []): Collection
    {
        // The daily book only needs the fields rendered by the page. Keeping
        // the projection small is especially important for desktop deployments
        // where PHP and MariaDB share the same machine.
        $query = DailySale::query()
            ->select([
                'id', 'company_id', 'sale_number', 'sale_date', 'customer_name',
                'customer_id', 'status', 'notes', 'signature_name', 'total_amount',
                'paid_amount', 'payment_method', 'total_quantity', 'created_by',
                'updated_by', 'finalized_at', 'inventory_applied_at', 'created_at',
                'updated_at',
            ])
            ->with([
                'items' => fn ($items) => $items->select([
                    'id', 'daily_sale_id', 'company_id', 'line_number', 'product_id',
                    'product_name', 'unit', 'conversion_mode', 'conversion_factor', 'quantity', 'base_quantity',
                    'warehouse_id', 'location_id', 'trace_allocations', 'unit_price', 'unit_cost',
                    'line_total', 'cost_total', 'gross_profit',
                    'created_at', 'updated_at',
                ])->orderBy('line_number'),
                'items.product' => fn ($products) => $products->select([
                    'id', 'company_id', 'name', 'unit', 'quantity', 'price',
                    'purchase_price', 'selling_price', 'default_warehouse_id',
                    'tracking_mode', 'expiration_controlled', 'near_expiry_days', 'fefo_enabled',
                ]),
                'creator:id,name',
            ]);

        if (! empty($filters['date'])) {
            // whereDate keeps this compatible with SQLite desktop databases,
            // which may persist a DATE cast with a midnight time component.
            $query->whereDate('sale_date', $filters['date'])->oldest('id');
        } else {
            $query->latest('sale_date')->latest('id');
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    public function find(int $id): DailySale
    {
        return DailySale::with(['items.product', 'creator:id,name'])->findOrFail($id);
    }

    public function create(array $data): DailySale
    {
        return DB::transaction(function () use ($data) {
            $user = Auth::user();
            $key = (string) ($data['idempotency_key'] ?? Str::uuid());
            $fingerprint = RequestFingerprint::make($data, ['idempotency_key']);
            // Serialize numbering and retry detection for this company. Stock
            // itself remains protected by the product/bin row locks.
            DB::table('companies')->where('id', $user->company_id)->lockForUpdate()->first();
            $existing = DailySale::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->request_fingerprint
                    && ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This idempotency key was already used for different sale details.'],
                    ]);
                }

                return $existing->load(['items.product', 'creator:id,name']);
            }
            $items = $this->normalizeItems($data['items'] ?? []);
            $totals = $this->calculateTotals($items);

            $sale = DailySale::create([
                'company_id' => $user->company_id,
                'sale_number' => $this->generateSaleNumber($user->company_id, $data['sale_date']),
                'sale_date' => $data['sale_date'],
                'customer_name' => null,
                'customer_id' => null,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'signature_name' => $data['signature_name'] ?? null,
                'total_amount' => $totals['amount'],
                'paid_amount' => $totals['amount'],
                'payment_method' => null,
                'total_quantity' => $totals['quantity'],
                'idempotency_key' => $key,
                'request_fingerprint' => $fingerprint,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->syncItems($sale, $items);

            $this->applyInventory(
                $sale->fresh('items'),
                'out',
                'Recorded daily sale',
                (bool) ($data['allow_expired_override'] ?? false),
                $data['expired_override_reason'] ?? null,
            );
            $sale->update(['inventory_applied_at' => now()]);

            return $sale->fresh(['items.product', 'creator:id,name']);
        });
    }

    public function update(DailySale $sale, array $data): DailySale
    {
        return DB::transaction(function () use ($sale, $data) {
            $sale = DailySale::query()->lockForUpdate()->findOrFail($sale->id);
            $this->ensureDraft($sale);
            $sale->load('items');
            if ($sale->inventory_applied_at) {
                $this->applyInventory($sale, 'in', 'Reversed before editing daily sale');
            }

            $items = $this->normalizeItems($data['items'] ?? []);
            $totals = $this->calculateTotals($items);

            $sale->update([
                'sale_date' => $data['sale_date'] ?? $sale->sale_date,
                'customer_name' => null,
                'customer_id' => null,
                'notes' => $data['notes'] ?? null,
                'signature_name' => $data['signature_name'] ?? null,
                'total_amount' => $totals['amount'],
                'paid_amount' => $totals['amount'],
                'payment_method' => null,
                'total_quantity' => $totals['quantity'],
                'updated_by' => Auth::id(),
            ]);

            $sale->items()->delete();
            $this->syncItems($sale, $items);
            $this->applyInventory(
                $sale->fresh('items'),
                'out',
                'Updated daily sale',
                (bool) ($data['allow_expired_override'] ?? false),
                $data['expired_override_reason'] ?? null,
            );
            $sale->update(['inventory_applied_at' => now()]);

            return $sale->fresh(['items.product', 'creator:id,name']);
        });
    }

    public function finalize(DailySale $sale): DailySale
    {
        $this->ensureDraft($sale);

        return DB::transaction(function () use ($sale) {
            $sale = DailySale::query()->lockForUpdate()->findOrFail($sale->id);
            $sale->load('items');

            $this->ensureDraft($sale);

            if ($sale->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['Add at least one line item before finalizing.'],
                ]);
            }

            if (! $sale->inventory_applied_at) {
                $this->applyInventory($sale, 'out', 'Finalized legacy daily sale');
            }

            $sale->update([
                'status' => 'finalized',
                'finalized_at' => now(),
                'inventory_applied_at' => $sale->inventory_applied_at ?? now(),
                'updated_by' => Auth::id(),
            ]);

            return $sale->fresh(['items.product', 'creator:id,name']);
        });
    }

    public function finalizeDay(string $date): Collection
    {
        return DB::transaction(function () use ($date) {
            $sales = DailySale::query()
                ->whereDate('sale_date', $date)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($sales->isEmpty()) {
                throw ValidationException::withMessages(['date' => ['Add at least one sale before closing the day.']]);
            }

            foreach ($sales->where('status', 'draft') as $sale) {
                $this->finalize($sale);
            }

            return DailySale::with(['items.product', 'creator:id,name'])
                ->whereDate('sale_date', $date)
                ->orderBy('id')
                ->get();
        });
    }

    public function destroy(DailySale $sale): void
    {
        DB::transaction(function () use ($sale) {
            $sale = DailySale::query()->lockForUpdate()->findOrFail($sale->id);
            if ($sale->status === 'finalized') {
                throw ValidationException::withMessages([
                    'status' => ['Finalized daily sales sheets cannot be deleted.'],
                ]);
            }
            $sale->load('items');
            if ($sale->inventory_applied_at) {
                $this->applyInventory($sale, 'in', 'Deleted daily sale');
            }
            $sale->delete();
        });
    }

    public function todaySummary(?string $date = null): array
    {
        $companyId = Auth::user()->company_id;
        $targetDate = $date ?? now()->toDateString();

        $allSales = DailySale::where('company_id', $companyId)
            ->whereDate('sale_date', $targetDate)
            ->get();
        $sales = $allSales
            ->where('status', 'finalized');

        return [
            'date' => $targetDate,
            'total_sales' => (float) $sales->sum('total_amount'),
            'transaction_count' => $sales->count(),
            'total_quantity' => (float) $sales->sum('total_quantity'),
            'draft_sales' => $allSales->where('status', 'draft')->count(),
            'draft_total' => (float) $allSales->where('status', 'draft')->sum('total_amount'),
            'day_total' => (float) $allSales->sum('total_amount'),
            'day_quantity' => (float) $allSales->sum('total_quantity'),
            'day_closed' => $allSales->isNotEmpty() && $allSales->every(fn (DailySale $sale) => $sale->status === 'finalized'),
        ];
    }

    public function dayNotes(string $date): ?string
    {
        return DailySalesDay::query()->whereDate('sale_date', $date)->value('notes');
    }

    public function updateDayNotes(string $date, ?string $notes): string
    {
        $day = DailySalesDay::updateOrCreate(
            ['company_id' => Auth::user()->company_id, 'sale_date' => $date],
            ['notes' => $notes === '' ? null : $notes],
        );

        return (string) ($day->notes ?? '');
    }

    private function syncItems(DailySale $sale, array $items): void
    {
        foreach ($items as $index => $item) {
            DailySaleItem::create([
                'daily_sale_id' => $sale->id,
                'company_id' => $sale->company_id,
                'line_number' => $index + 1,
                'product_id' => $item['product_id'] ?? null,
                'product_name' => $item['product_name'],
                'unit' => $item['unit'],
                'conversion_mode' => $item['conversion_mode'],
                'conversion_factor' => $item['conversion_factor'],
                'quantity' => $item['quantity'],
                'base_quantity' => $item['base_quantity'],
                'warehouse_id' => $item['warehouse_id'],
                'location_id' => $item['location_id'],
                'trace_allocations' => $item['trace_allocations'],
                'unit_price' => $item['unit_price'],
                'unit_cost' => $item['unit_cost'],
                'line_total' => $item['line_total'],
                'cost_total' => $item['cost_total'],
                'gross_profit' => $item['gross_profit'],
            ]);
        }
    }

    private function applyInventory(
        DailySale $sale,
        string $type,
        string $action,
        bool $allowExpiredOverride = false,
        ?string $expiredOverrideReason = null,
    ): void
    {
        $inventoryRevision = $type === 'out'
            ? 1 + (int) StockMovement::withoutGlobalScopes()
                ->where('source_type', 'daily_sale')->where('source_id', $sale->id)
                ->where('movement_code', 'daily_sale')->get()
                ->max(fn (StockMovement $movement) => (int) ($movement->metadata['inventory_revision'] ?? 0))
            : null;
        $quantities = $sale->items
            ->whereNotNull('product_id')
            ->groupBy(fn ($item) => $item->product_id.'|'.($item->warehouse_id ?? 0).'|'.($item->location_id ?? 0))
            ->map(fn ($items) => [
                'product_id' => (int) $items->first()->product_id,
                'warehouse_id' => $items->first()->warehouse_id,
                'location_id' => $items->first()->location_id,
                'quantity' => round((float) $items->sum(fn ($item) => (float) ($item->base_quantity ?? $item->quantity)), 3),
                'cost_total' => $items->contains(fn ($item) => $item->cost_total === null)
                    ? null
                    : round((float) $items->sum(fn ($item) => (float) $item->cost_total), 6),
                'trace_allocations' => $items->flatMap(fn ($item) => $item->trace_allocations ?? [])->values()->all(),
            ]);

        foreach ($quantities as $group) {
            $groupKey = $group['product_id'].'|'.($group['warehouse_id'] ?? 0).'|'.($group['location_id'] ?? 0);
            $stockKey = "daily-sale-stock-{$sale->id}-rev-{$inventoryRevision}-{$group['product_id']}-".($group['warehouse_id'] ?? 'auto').'-'.($group['location_id'] ?? 'auto');
            $movement = [
                'product_id' => $group['product_id'],
                'warehouse_id' => $group['warehouse_id'],
                'location_id' => $group['location_id'],
                'type' => $type,
                'quantity' => $group['quantity'],
                'reason' => "{$action}: {$sale->sale_number}",
                'movement_code' => $type === 'out' ? 'daily_sale' : 'daily_sale_reversal',
                'source_type' => 'daily_sale',
                'source_id' => $sale->id,
                'trace_allocations' => $group['trace_allocations'],
                'allow_expired_override' => $type === 'out' && $allowExpiredOverride,
                'expired_override_reason' => $type === 'out' ? $expiredOverrideReason : null,
                'idempotency_key' => $type === 'out' ? $stockKey : null,
                'metadata' => $type === 'out' ? [
                    'inventory_revision' => $inventoryRevision,
                    'inventory_group_key' => $groupKey,
                ] : null,
            ];
            if ($type === 'out') {
                $this->stockMovements->storeOutboundAllocated($movement);
                continue;
            }

            $outbound = $this->outboundMovementsForGroup('daily_sale', $sale->id, 'daily_sale', 'daily_sale_reversal', $group, $groupKey);
            if ($outbound->isEmpty() || abs(round((float) $outbound->sum('quantity'), 3) - (float) $group['quantity']) >= 0.0005) {
                throw ValidationException::withMessages(['items' => ['The saved sale no longer matches its inventory movements; reconcile it before reversal.']]);
            }
            foreach ($outbound as $original) {
                $this->stockMovements->store([
                    ...$movement,
                    'warehouse_id' => $original->warehouse_id,
                    'location_id' => $original->location_id,
                    'quantity' => (float) $original->quantity,
                    'final_unit_cost' => $original->final_unit_cost,
                    'trace_allocations' => $original->traceLines->map(fn ($line) => [
                        'inventory_lot_id' => (int) $line->inventory_lot_id,
                        'quantity' => (float) $line->quantity,
                    ])->values()->all(),
                    'idempotency_key' => "daily-sale-stock-{$sale->id}-in-{$original->id}",
                    'metadata' => ['reverses_stock_movement_id' => $original->id],
                ]);
            }
        }
    }

    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $product = null;
            if (! empty($item['product_id'])) {
                // Capture the purchase cost while the product row is locked so
                // later price changes cannot alter this sale's historical margin.
                $product = Product::query()->with('units')->lockForUpdate()->find($item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => ['One or more selected products are unavailable.'],
                    ]);
                }
            }

            $quantity = max(0.001, (float) ($item['quantity'] ?? 1));
            $resolvedUnit = $product
                ? $this->unitConversions->resolve(
                    $product,
                    $quantity,
                    $item['unit'] ?? $this->unitConversions->defaultUnit($product, 'sale'),
                    isset($item['actual_base_quantity']) ? (float) $item['actual_base_quantity'] : null,
                    'sale',
                )
                : ['unit' => $item['unit'] ?? 'pcs', 'inventory_unit' => $item['unit'] ?? 'pcs', 'conversion_mode' => 'none', 'conversion_factor' => null, 'base_quantity' => $quantity];
            $unitPrice = Money::normalize($item['unit_price'] ?? ($product?->selling_price ?? $product?->price ?? 0));
            $productName = trim($item['product_name'] ?? $product?->name ?? '');
            // `price` is the legacy selling-price field. Falling back to it as
            // a purchase cost would silently report a zero margin, so a missing
            // purchase price remains uncosted and is disclosed by analytics.
            $baseUnitCost = $product ? $this->costing->currentUnitCost($product) : null;
            $lineTotal = Money::multiply($unitPrice, $quantity);
            $costTotal = $baseUnitCost === null ? null : Money::multiply($baseUnitCost, $resolvedUnit['base_quantity']);
            $unitCost = $costTotal === null ? null : Money::divide($costTotal, $quantity, 2);

            if ($productName === '') {
                continue;
            }

            $normalized[] = [
                'product_id' => $product?->id,
                'product_name' => $productName,
                // Inventory product data is authoritative; custom lines retain their entered unit.
                'unit' => $resolvedUnit['unit'],
                'conversion_mode' => $resolvedUnit['conversion_mode'],
                'conversion_factor' => $resolvedUnit['conversion_factor'],
                'quantity' => $quantity,
                'base_quantity' => $resolvedUnit['base_quantity'],
                'warehouse_id' => $product ? ($item['warehouse_id'] ?? $product->default_warehouse_id) : null,
                'location_id' => $product ? ($item['location_id'] ?? null) : null,
                'trace_allocations' => $product ? ($item['trace_allocations'] ?? []) : [],
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'line_total' => $lineTotal,
                'cost_total' => $costTotal,
                'gross_profit' => $costTotal === null ? null : Money::subtract($lineTotal, $costTotal),
            ];
        }

        return $normalized;
    }

    private function movementTrace(string $sourceType, int $sourceId, string $movementCode, array $group): array
    {
        return StockMovement::withoutGlobalScopes()
            ->with('traceLines')
            ->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('movement_code', $movementCode)->where('product_id', $group['product_id'])
            ->where('warehouse_id', $group['warehouse_id'])
            ->where('location_id', $group['location_id'])
            ->get()->flatMap(fn ($movement) => $movement->traceLines)
            ->groupBy('inventory_lot_id')
            ->map(fn ($lines, $lotId) => [
                'inventory_lot_id' => (int) $lotId,
                'quantity' => round((float) $lines->sum('quantity'), 3),
            ])->values()->all();
    }

    private function outboundMovementsForGroup(
        string $sourceType,
        int $sourceId,
        string $movementCode,
        string $reversalCode,
        array $group,
        string $groupKey,
    ): \Illuminate\Database\Eloquent\Collection {
        $outbound = StockMovement::withoutGlobalScopes()->with('traceLines')
            ->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('movement_code', $movementCode)->where('product_id', $group['product_id'])
            ->orderBy('id')->get();
        $reversals = StockMovement::withoutGlobalScopes()
            ->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('movement_code', $reversalCode)->get();
        $reversedIds = $reversals->pluck('metadata')->map(fn ($metadata) => (int) ($metadata['reverses_stock_movement_id'] ?? 0))->filter();
        $active = $outbound->reject(fn (StockMovement $movement) => $reversedIds->contains((int) $movement->id));
        $keyed = $active->filter(fn (StockMovement $movement) => ($movement->metadata['inventory_group_key'] ?? null) === $groupKey)->values();
        if ($keyed->isNotEmpty()) {
            return new \Illuminate\Database\Eloquent\Collection($keyed->all());
        }

        $lastLegacyReversalId = (int) $reversals->filter(fn (StockMovement $movement) => empty($movement->metadata['reverses_stock_movement_id']))->max('id');
        $legacy = $active->filter(fn (StockMovement $movement) =>
            empty($movement->metadata['inventory_group_key'])
            && (int) $movement->id > $lastLegacyReversalId
            && (int) ($movement->location_id ?? 0) === (int) ($group['location_id'] ?? 0)
        )->values();

        return new \Illuminate\Database\Eloquent\Collection($legacy->all());
    }

    private function calculateTotals(array $items): array
    {
        return [
            'amount' => Money::add(...array_column($items, 'line_total')),
            'quantity' => array_sum(array_column($items, 'quantity')),
        ];
    }

    private function generateSaleNumber(int $companyId, string $saleDate): string
    {
        $prefix = 'DS-'.str_replace('-', '', $saleDate).'-';
        $latest = DailySale::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('sale_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('sale_number');

        $sequence = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }

    private function ensureDraft(DailySale $sale): void
    {
        if ($sale->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['Only draft daily sales sheets can be modified.'],
            ]);
        }
    }
}
