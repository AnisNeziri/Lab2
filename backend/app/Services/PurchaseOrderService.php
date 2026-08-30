<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderChange;
use App\Models\PurchaseOrderPayment;
use App\Models\GoodsReceipt;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Warehouse;
use App\Support\Money;
use App\Models\WarehouseLocation;
use App\Support\RequestFingerprint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderService
{
    public function __construct(
        private readonly StockMovementService $stockMovements,
        private readonly UnitConversionService $units,
        private readonly WarehouseLayoutService $warehouseLayout,
        private readonly FinancialAccountService $financialAccounts,
        private readonly SupplierPaymentAllocationService $paymentAllocations,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $query = PurchaseOrder::query()->with(['supplier:id,name,phone,email', 'warehouse:id,name,code']);

        if ($search = $filters['search'] ?? null) {
            $query->where(fn ($builder) => $builder
                ->where('po_number', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', "%{$search}%")));
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['ordered_at'])) {
            $query->whereDate('ordered_at', $filters['ordered_at']);
        }
        if (! empty($filters['due_at'])) {
            $query->whereDate('due_at', $filters['due_at']);
        }

        $businessDate = now('Europe/Tirane')->toDateString();
        match ($filters['payment_status'] ?? null) {
            'paid' => $query->whereColumn('total_paid', '>=', 'total_amount'),
            'partially_paid' => $query->where('total_paid', '>', 0)->whereColumn('total_paid', '<', 'total_amount')->where(fn ($q) => $q->whereNull('due_at')->orWhereDate('due_at', '>=', $businessDate)),
            'unpaid' => $query->where('total_paid', 0)->where(fn ($q) => $q->whereNull('due_at')->orWhereDate('due_at', '>=', $businessDate)),
            'overdue' => $query->whereColumn('total_paid', '<', 'total_amount')->whereDate('due_at', '<', $businessDate)->where('status', '!=', 'cancelled'),
            default => null,
        };
        if (! empty($filters['overdue'])) {
            $query->whereColumn('total_paid', '<', 'total_amount')->whereDate('due_at', '<', $businessDate)->where('status', '!=', 'cancelled');
        }

        match ($filters['sort'] ?? 'recent') {
            'total_desc' => $query->orderByDesc('total_amount'),
            'due_asc' => $query->orderByRaw('due_at IS NULL')->orderBy('due_at'),
            default => $query->latest('ordered_at')->latest('id'),
        };

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function find(PurchaseOrder $order): PurchaseOrder
    {
        return $order->load([
            'supplier', 'warehouse', 'items.product:id,name,sku,unit,tracking_mode', 'items.productSupplier',
            'payments' => fn ($query) => $query
                ->with(['user:id,name', 'allocations.expense:id,purchase_order_id,document_type,document_number,gross_amount,currency,status'])
                ->latest('payment_date')->latest('id'),
            'supplierInvoices' => fn ($query) => $query
                ->withSum(['payments as payments_sum_amount' => fn ($payment) => $payment->where('status', 'completed')], 'amount')
                ->withSum('purchaseOrderPaymentAllocations', 'amount')
                ->withSum('supplierCredits', 'gross_amount')
                ->latest('invoice_date')->latest('id'),
            'changes' => fn ($query) => $query->with('user:id,name')->latest(),
            'goodsReceipts' => fn ($query) => $query->with(['warehouse:id,name,code', 'location:id,name,code,path', 'receiver:id,name', 'items.product:id,name,sku,unit,tracking_mode'])->latest('received_at'),
            'shipments' => fn ($query) => $query->select([
                'id', 'purchase_order_id', 'tracking_number', 'vessel_name', 'mmsi', 'imo',
                'status', 'current_lat', 'current_lng', 'position_updated_at', 'archived_at',
            ])->latest(),
        ]);
    }

    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $total = $this->total($data['items']);
            $money = $this->currencySnapshot($data['currency'], $total, $data);
            $warehouseId = $data['warehouse_id'] ?? Warehouse::query()->where('is_default', true)->value('id') ?? $this->warehouseLayout->getOrCreatePrimaryWarehouse((int) Auth::user()->company_id)->id;
            $order = PurchaseOrder::create([
                'company_id' => Auth::user()->company_id,
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $warehouseId,
                'po_number' => $this->nextNumber(),
                'status' => $data['status'] ?? 'draft',
                'total_amount' => $total,
                'total_amount_eur' => $money['total_amount_eur'],
                'total_paid' => 0,
                'currency' => $data['currency'],
                'exchange_rate' => $money['exchange_rate'],
                'exchange_rate_date' => $money['exchange_rate_date'],
                'exchange_rate_source' => $money['exchange_rate_source'],
                'ordered_at' => $data['ordered_at'],
                'expected_at' => $data['expected_at'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
            $this->syncItems($order, $data['items']);
            $this->audit($order, 'created', null, $this->snapshot($order->fresh('items')), $data['change_reason'] ?? null);

            return $this->find($order->fresh());
        });
    }

    public function update(PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (in_array($order->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => ['Completed or cancelled orders cannot have their products changed.']]);
            }

            $old = $this->snapshot($order->load('items'));
            $hasExistingReceipts = $order->goodsReceipts()->exists();
            if ($hasExistingReceipts && (int) $data['supplier_id'] !== (int) $order->supplier_id) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['The supplier cannot be changed after products have been received.'],
                ]);
            }
            if (! $hasExistingReceipts && isset($data['status']) && $data['status'] !== $order->status) {
                $this->assertStatusTransition($order->status, $data['status']);
            }
            $total = $this->total($data['items']);
            $money = $this->currencySnapshot($data['currency'], $total, $data);
            if (Money::compare($total, $order->total_paid) < 0) {
                throw ValidationException::withMessages(['items' => ['The new order total cannot be lower than the amount already paid.']]);
            }

            $order->update([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'] ?? $order->warehouse_id,
                // Receipt history owns the operational state. A generic edit
                // must not roll a received order back to draft/ordered.
                'status' => $hasExistingReceipts ? $order->status : ($data['status'] ?? $order->status),
                'total_amount' => $total,
                'total_amount_eur' => $money['total_amount_eur'],
                'currency' => $data['currency'],
                'exchange_rate' => $money['exchange_rate'],
                'exchange_rate_date' => $money['exchange_rate_date'],
                'exchange_rate_source' => $money['exchange_rate_source'],
                'ordered_at' => $data['ordered_at'],
                'expected_at' => $data['expected_at'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => Auth::id(),
            ]);
            $this->syncItems($order, $data['items'], true);
            $items = $order->items()->get();
            $hasReceipts = $items->contains(fn ($item) => (float) $item->received_quantity > 0);
            if ($hasReceipts) {
                $allReceived = $items->every(fn ($item) => $item->remaining_quantity <= 0);
                $order->update([
                    'status' => $allReceived ? 'received' : 'partially_received',
                    'received_at' => $allReceived ? ($order->received_at ?? now()) : null,
                ]);
            }
            $new = $this->snapshot($order->fresh('items'));
            $this->audit($order, 'updated', $old, $new, $data['change_reason'] ?? null);

            return $this->find($order->fresh());
        });
    }

    public function pay(PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => ['Cancelled orders cannot receive payments.']]);
            }
            $existing = PurchaseOrderPayment::where('company_id', $order->company_id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $requestedExpenseId = (int) ($data['expense_id'] ?? 0);
                $originalExpenseId = (int) (DB::table('supplier_payment_allocation_requests')
                    ->where('company_id', $order->company_id)
                    ->where('idempotency_key', $data['idempotency_key'].'-allocation')
                    ->value('expense_id') ?? 0);
                $same = (int) $existing->purchase_order_id === (int) $order->id
                    && $existing->status === 'completed'
                    && Money::compare($existing->amount, $data['amount']) === 0
                    && $existing->payment_date?->toDateString() === (string) $data['payment_date']
                    && $existing->payment_method === $data['payment_method']
                    && ($existing->reference_number ?? '') === ($data['reference_number'] ?? '')
                    && ($existing->note ?? '') === ($data['note'] ?? '')
                    && (int) ($existing->financial_account_id ?? 0) === (int) ($data['financial_account_id'] ?? 0)
                    && $originalExpenseId === $requestedExpenseId;
                if (! $same) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This payment key was already used with different payment details.'],
                    ]);
                }

                return $this->find($order);
            }

            $amount = Money::normalize($data['amount']);
            if (Money::compare($amount, $order->remaining_balance) > 0) {
                throw ValidationException::withMessages(['amount' => ['Payment cannot exceed the remaining order balance.']]);
            }

            $linkedExpense = ! empty($data['expense_id'])
                ? Expense::query()->findOrFail((int) $data['expense_id'])
                : null;
            $payment = PurchaseOrderPayment::create([
                'company_id' => $order->company_id,
                'purchase_order_id' => $order->id,
                'user_id' => Auth::id(),
                'amount' => $amount,
                'currency' => $order->currency,
                'exchange_rate' => $order->exchange_rate ?: 1,
                'amount_eur' => Money::multiply($amount, $order->exchange_rate ?: 1),
                'status' => 'completed',
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'idempotency_key' => $data['idempotency_key'],
                'note' => $data['note'] ?? null,
                'paid_at' => now(),
                'expense_id' => null,
                'financial_account_id' => $data['financial_account_id'] ?? null,
            ]);
            if (! empty($data['financial_account_id'])) {
                $ledger = $this->financialAccounts->post((int) $data['financial_account_id'], [
                    'type' => 'outflow', 'amount' => Money::multiply($amount, $order->exchange_rate ?: 1),
                    'transaction_date' => $data['payment_date'], 'source_type' => 'purchase_order_payment',
                    'source_id' => $payment->id, 'counterparty' => $order->supplier?->name,
                    'reference_number' => $data['reference_number'] ?? null,
                    'description' => 'Purchase Order payment '.$order->po_number,
                    'idempotency_key' => $data['idempotency_key'].'-ledger',
                ]);
                $payment->update(['financial_account_transaction_id' => $ledger->id]);
            }
            $before = Money::normalize($order->total_paid);
            $order->update(['total_paid' => Money::add($before, $amount), 'updated_by' => Auth::id()]);
            if ($linkedExpense) {
                $this->paymentAllocations->allocate(
                    $payment,
                    $linkedExpense,
                    $amount,
                    $data['note'] ?? 'Allocated when the Purchase Order payment was recorded.',
                    $data['idempotency_key'].'-allocation',
                );
            }
            $this->audit($order, 'payment_recorded', ['total_paid' => $before], ['total_paid' => Money::normalize($order->total_paid), 'amount' => $amount], $data['note'] ?? null);

            return $this->find($order->fresh());
        });
    }

    public function reversePayment(PurchaseOrderPayment $payment, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($payment, $reason) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($payment->purchase_order_id);
            $payment = PurchaseOrderPayment::query()
                ->where('purchase_order_id', $order->id)
                ->lockForUpdate()
                ->findOrFail($payment->id);
            if ($payment->status !== 'completed' || $payment->reversed_at) {
                throw ValidationException::withMessages(['payment' => ['Only an active Purchase Order payment can be reversed.']]);
            }

            foreach ($payment->allocations()->lockForUpdate()->get() as $allocation) {
                $oldAmount = $allocation->amount;
                DB::table('supplier_payment_allocation_requests')
                    ->where('purchase_order_payment_id', $payment->id)
                    ->where('expense_id', $allocation->expense_id)
                    ->where('status', 'completed')
                    ->update([
                        'status' => 'reversed', 'reversed_at' => now(),
                        'reversed_by' => Auth::id(), 'reversal_reason' => trim($reason),
                        'updated_at' => now(),
                    ]);
                $allocation->update([
                    'amount' => '0.00', 'allocated_by' => Auth::id(), 'allocated_at' => now(),
                    'reason' => 'Reversed with Purchase Order payment: '.trim($reason),
                ]);
                \App\Models\SupplierMatchEvent::create([
                    'company_id' => $order->company_id, 'expense_id' => $allocation->expense_id,
                    'action' => 'purchase_order_payment_allocation_reversed',
                    'old_values' => ['purchase_order_payment_id' => $payment->id, 'amount' => $oldAmount],
                    'new_values' => ['purchase_order_payment_id' => $payment->id, 'amount' => '0.00'],
                    'reason' => trim($reason), 'user_id' => Auth::id(), 'created_at' => now(),
                ]);
            }

            $payment->update([
                'status' => 'reversed', 'expense_id' => null, 'reversed_at' => now(),
                'reversed_by' => Auth::id(), 'reversal_reason' => trim($reason),
            ]);
            if ($payment->financial_account_transaction_id) {
                $ledger = \App\Models\FinancialAccountTransaction::query()->find($payment->financial_account_transaction_id);
                if ($ledger && $ledger->status === 'posted') {
                    $this->financialAccounts->reverse($ledger, 'Purchase Order payment reversal: '.trim($reason));
                }
            }

            $before = Money::normalize($order->total_paid);
            $totalPaid = Money::normalize($order->payments()->where('status', 'completed')->sum('amount'));
            $order->update(['total_paid' => $totalPaid, 'updated_by' => Auth::id()]);
            $this->audit(
                $order,
                'payment_reversed',
                ['total_paid' => $before, 'payment_id' => $payment->id, 'amount' => $payment->amount],
                ['total_paid' => $totalPaid, 'payment_id' => $payment->id, 'status' => 'reversed'],
                trim($reason),
            );

            return $this->find($order->fresh());
        });
    }

    public function receive(PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            $requestFingerprint = RequestFingerprint::make($data, ['idempotency_key']);
            $existingReceipt = GoodsReceipt::withoutGlobalScopes()
                ->where('company_id', $order->company_id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existingReceipt) {
                if ((int) $existingReceipt->purchase_order_id !== (int) $order->id) {
                    throw ValidationException::withMessages(['idempotency_key' => ['This idempotency key was already used for another goods receipt.']]);
                }
                if (! $existingReceipt->request_fingerprint
                    || ! hash_equals($existingReceipt->request_fingerprint, $requestFingerprint)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This idempotency key was already used for different receipt details.'],
                    ]);
                }

                return $this->find($order);
            }
            if (! in_array($order->status, ['ordered', 'partially_received'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only an ordered or partially received Purchase Order can receive products.'],
                ]);
            }
            $warehouseId = (int) ($data['warehouse_id'] ?? $order->warehouse_id);
            $warehouse = Warehouse::query()->findOrFail($warehouseId);
            if (! empty($data['location_id']) && ! WarehouseLocation::query()
                ->whereKey((int) $data['location_id'])
                ->where('warehouse_id', $warehouse->id)
                ->where('is_active', true)
                ->exists()) {
                throw ValidationException::withMessages([
                    'location_id' => ['The receipt location must be active and belong to the selected warehouse.'],
                ]);
            }
            $receipt = GoodsReceipt::create([
                'company_id' => $order->company_id,
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $data['location_id'] ?? null,
                'receipt_number' => $this->nextReceiptNumber((int) $order->company_id),
                'supplier_document_number' => $data['supplier_document_number'] ?? null,
                'received_at' => $data['received_at'] ?? now(),
                'status' => 'posted',
                'notes' => $data['notes'] ?? ($data['reason'] ?? null),
                'received_by' => Auth::id(),
                'idempotency_key' => $data['idempotency_key'],
                'request_fingerprint' => $requestFingerprint,
            ]);
            $order->load('items.product');
            $received = [];
            foreach ($data['items'] as $input) {
                $item = $order->items->firstWhere('id', (int) $input['id']);
                if (! $item) {
                    throw ValidationException::withMessages(['items' => ['A received item does not belong to this order.']]);
                }
                if (! $item->product_id) {
                    throw ValidationException::withMessages(['items' => ['Every received line must be linked to an inventory product.']]);
                }
                if (($item->product->lifecycle_status ?? 'active') === 'archived') {
                    throw ValidationException::withMessages([
                        'items' => ["Archived product {$item->product->name} cannot be received."],
                    ]);
                }
                $accepted = round((float) ($input['accepted_quantity'] ?? $input['quantity'] ?? 0), 3);
                $damaged = round((float) ($input['damaged_quantity'] ?? 0), 3);
                $rejected = round((float) ($input['rejected_quantity'] ?? 0), 3);
                $quantity = round($accepted + $damaged, 3);
                $inspectedQuantity = round($accepted + $damaged + $rejected, 3);
                if (($item->product->lifecycle_status ?? 'active') === 'discontinued' && $quantity > 0) {
                    throw ValidationException::withMessages([
                        'items' => ["Discontinued product {$item->product->name} cannot be replenished. Record only rejected quantity or reactivate the product first."],
                    ]);
                }
                if ($accepted < 0 || $damaged < 0 || $rejected < 0 || $inspectedQuantity <= 0) {
                    throw ValidationException::withMessages(['items' => ["Enter an accepted, damaged, or rejected quantity for {$item->description}."]]);
                }
                if ($inspectedQuantity > $item->remaining_quantity + 0.0005) {
                    throw ValidationException::withMessages(['items' => ["Accepted, damaged, and rejected quantities for {$item->description} exceed the quantity still expected."]]);
                }

                $acceptedBase = $accepted > 0 ? $this->units->resolveSnapshot(
                    $item->inventory_unit ?: $item->product->unit,
                    $item->unit,
                    $item->conversion_mode ?: 'none',
                    $item->conversion_factor !== null ? (float) $item->conversion_factor : null,
                    $accepted,
                    isset($input['accepted_base_quantity']) ? (float) $input['accepted_base_quantity'] : null,
                ) : 0;
                $damagedBase = $damaged > 0 ? $this->units->resolveSnapshot(
                    $item->inventory_unit ?: $item->product->unit,
                    $item->unit,
                    $item->conversion_mode ?: 'none',
                    $item->conversion_factor !== null ? (float) $item->conversion_factor : null,
                    $damaged,
                    isset($input['damaged_base_quantity']) ? (float) $input['damaged_base_quantity'] : null,
                ) : 0;
                $inventoryBaseQuantity = round($acceptedBase + $damagedBase, 3);
                $purchaseExchangeRate = Money::normalizeDecimal($order->exchange_rate ?: '1', 8);
                $basePurchaseCost = Money::product([$quantity, $item->unit_price, $purchaseExchangeRate], 6);
                $basePurchaseUnitCost = $inventoryBaseQuantity > 0
                    ? Money::divide($basePurchaseCost, $inventoryBaseQuantity, 6)
                    : null;
                $costedMovements = [];
                foreach ([
                    ['quantity' => $acceptedBase, 'state' => 'available', 'code' => 'purchase_receipt'],
                    ['quantity' => $damagedBase, 'state' => 'damaged', 'code' => 'damage_received'],
                ] as $portion) {
                    if ($portion['quantity'] <= 0) {
                        continue;
                    }
                    $portionTraceAllocations = collect($input['trace_allocations'] ?? [])
                        ->where('stock_state', $portion['state'])
                        ->map(function (array $allocation): array {
                            unset($allocation['stock_state']);

                            return $allocation;
                        })->values()->all();
                    $costedMovements[] = $this->stockMovements->store([
                        'product_id' => $item->product_id,
                        'warehouse_id' => $warehouse->id,
                        'location_id' => $receipt->location_id,
                        'type' => 'in',
                        'quantity' => $portion['quantity'],
                        'stock_state' => $portion['state'],
                        'reason' => $data['reason'] ?? "Goods receipt {$receipt->receipt_number} for {$order->po_number}",
                        'movement_code' => $portion['code'],
                        'source_type' => 'goods_receipt',
                        'source_id' => $receipt->id,
                        'idempotency_key' => $data['idempotency_key'].'-'.$portion['state'].'-'.$item->id,
                        'base_purchase_unit_cost' => $basePurchaseUnitCost,
                        'landed_cost_unit' => 0,
                        'final_unit_cost' => $basePurchaseUnitCost,
                        'trace_allocations' => $portionTraceAllocations,
                    ]);
                }
                $item->update([
                    'received_quantity' => round((float) $item->received_quantity + $quantity, 3),
                    'received_base_quantity' => round((float) $item->received_base_quantity + $acceptedBase + $damagedBase, 3),
                ]);
                $receipt->items()->create([
                    'purchase_order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'ordered_unit' => $item->unit,
                    'accepted_quantity' => $accepted,
                    'damaged_quantity' => $damaged,
                    'rejected_quantity' => $rejected,
                    'accepted_base_quantity' => $acceptedBase,
                    'damaged_base_quantity' => $damagedBase,
                    'inventory_unit' => $item->inventory_unit ?: $item->product->unit,
                    'conversion_mode' => $item->conversion_mode ?: 'none',
                    'conversion_factor' => $item->conversion_factor,
                    'purchase_unit_price' => $item->unit_price,
                    'purchase_currency' => $order->currency,
                    'purchase_exchange_rate' => $purchaseExchangeRate,
                    'base_purchase_cost' => $basePurchaseCost,
                    'base_purchase_unit_cost' => $basePurchaseUnitCost,
                    'landed_cost_allocated' => 0,
                    'landed_cost_unit' => 0,
                    'final_inventory_unit_cost' => $basePurchaseUnitCost,
                    'weighted_average_cost_before' => collect($costedMovements)->first()?->weighted_average_cost_before,
                    'weighted_average_cost_after' => collect($costedMovements)->last()?->weighted_average_cost_after,
                    'notes' => $input['notes'] ?? null,
                ]);
                $received[] = ['item_id' => $item->id, 'accepted' => $accepted, 'damaged' => $damaged, 'rejected' => $rejected, 'base_quantity' => $acceptedBase + $damagedBase];
            }

            if ($received === []) {
                return $this->find($order->fresh());
            }

            $allReceived = $order->items()->get()->every(fn ($item) => $item->remaining_quantity <= 0);
            $order->update(['status' => $allReceived ? 'received' : 'partially_received', 'received_at' => $allReceived ? now() : null, 'updated_by' => Auth::id()]);
            $this->audit($order, 'goods_receipt_posted', null, ['receipt_id' => $receipt->id, 'receipt_number' => $receipt->receipt_number, 'warehouse_id' => $warehouse->id, 'items' => $received, 'status' => $order->status], $data['reason'] ?? ($data['notes'] ?? null));

            return $this->find($order->fresh());
        });
    }

    public function changeStatus(PurchaseOrder $order, string $status, ?string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $status, $reason) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($status === $order->status) {
                return $this->find($order);
            }
            $this->assertStatusTransition($order->status, $status);
            $old = $order->status;
            $order->update(['status' => $status, 'updated_by' => Auth::id()]);
            $this->audit($order, 'status_changed', ['status' => $old], ['status' => $status], $reason);

            return $this->find($order->fresh());
        });
    }

    public function cancel(PurchaseOrder $order, ?string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (in_array($order->status, ['received', 'partially_received', 'completed'], true)) {
                throw ValidationException::withMessages(['status' => ['An order with received stock cannot be cancelled.']]);
            }
            if (Money::compare($order->total_paid, '0.00') > 0) {
                throw ValidationException::withMessages(['payments' => ['Refund or correct recorded payments before cancelling this order.']]);
            }
            $old = $order->status;
            $order->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => Auth::id(), 'updated_by' => Auth::id()]);
            $this->audit($order, 'cancelled', ['status' => $old], ['status' => 'cancelled'], $reason);

            return $this->find($order->fresh());
        });
    }

    public function deleteCompleted(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'completed') {
                throw ValidationException::withMessages(['status' => ['Only completed purchase orders can be deleted.']]);
            }

            $this->audit($order, 'deleted', ['status' => $order->status], null, 'Completed purchase order deleted.');
            $order->delete();
        });
    }

    public function statement(PurchaseOrder $order): StreamedResponse
    {
        $order = $this->find($order);

        return response()->streamDownload(function () use ($order) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Purchase order', $order->po_number, 'Supplier', $order->supplier?->name]);
            fputcsv($out, ['Order date', $order->ordered_at?->toDateString(), 'Due date', $order->due_at?->toDateString(), 'Currency', $order->currency]);
            fputcsv($out, ['Total', $order->total_amount, 'Paid', $order->total_paid, 'Remaining', $order->remaining_balance]);
            fputcsv($out, []);
            fputcsv($out, ['Product', 'Unit', 'Quantity', 'Received', 'Unit price', 'Line total']);
            foreach ($order->items as $item) {
                fputcsv($out, [$item->description, $item->unit, $item->quantity, $item->received_quantity, $item->unit_price, $item->line_total]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Payment date', 'Amount', 'Method', 'Reference', 'Note', 'User']);
            foreach ($order->payments as $payment) {
                fputcsv($out, [$payment->payment_date?->toDateString(), $payment->amount, $payment->payment_method, $payment->reference_number, $payment->note, $payment->user?->name]);
            }
            fclose($out);
        }, $order->po_number.'.csv', ['Content-Type' => 'text/csv']);
    }

    private function syncItems(PurchaseOrder $order, array $items, bool $editing = false): void
    {
        $existing = $editing ? $order->items()->get()->keyBy('id') : collect();
        $kept = [];
        foreach ($items as $item) {
            $line = ! empty($item['id']) ? $existing->get((int) $item['id']) : null;
            $lineHasReceiptHistory = $line?->receiptItems()->exists() ?? false;
            if ($line && (float) $item['quantity'] < (float) $line->received_quantity) {
                throw ValidationException::withMessages(['items' => ["Quantity for {$line->description} cannot be lower than the amount already received."]]);
            }
            if ($lineHasReceiptHistory
                && (int) ($item['product_id'] ?? 0) !== (int) ($line->product_id ?? 0)) {
                throw ValidationException::withMessages([
                    'items' => ["The product on received item {$line->description} cannot be changed."],
                ]);
            }
            $values = [
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'unit' => $item['unit'],
                'quantity' => round((float) $item['quantity'], 3),
                'unit_price' => Money::normalize($item['unit_price']),
                'line_total' => Money::multiply(Money::normalize($item['unit_price']), $item['quantity']),
            ];
            if (! empty($item['product_id'])) {
                $product = Product::query()->with('units')->findOrFail($item['product_id']);
                $lifecycle = $product->lifecycle_status ?? 'active';
                if ($lifecycle === 'archived') {
                    throw ValidationException::withMessages([
                        'items' => ["Archived product {$product->name} cannot be purchased."],
                    ]);
                }
                $sameExistingProduct = $line && (int) $line->product_id === (int) $product->id;
                if ($lifecycle === 'discontinued' && ! $sameExistingProduct) {
                    throw ValidationException::withMessages([
                        'items' => ["Discontinued product {$product->name} cannot be added to a new Purchase Order line."],
                    ]);
                }
                $values['product_supplier_id'] = ProductSupplier::query()
                    ->where('product_id', $product->id)
                    ->where('supplier_id', $order->supplier_id)
                    ->value('id');
                $unitSnapshot = $this->units->describeOrderUnit($product, (float) $item['quantity'], $item['unit'], 'purchase');
                $values = [...$values, ...$unitSnapshot];
                if ($lineHasReceiptHistory
                    && ($line->unit !== $values['unit'] || $line->conversion_mode !== $values['conversion_mode'] || abs((float) $line->conversion_factor - (float) $values['conversion_factor']) > 0.000001)) {
                    throw ValidationException::withMessages(['items' => ["The unit conversion for received item {$line->description} cannot be changed."]]);
                }
            } else {
                $values['product_supplier_id'] = null;
                $values['inventory_unit'] = $item['unit'];
                $values['conversion_mode'] = 'none';
                $values['conversion_factor'] = null;
                $values['base_quantity'] = round((float) $item['quantity'], 3);
            }
            if ($line) {
                $line->update($values);
                $kept[] = $line->id;
            } else {
                $kept[] = $order->items()->create($values)->id;
            }
        }
        foreach ($existing->except($kept) as $line) {
            if ($line->receiptItems()->exists()) {
                throw ValidationException::withMessages(['items' => ["A received item ({$line->description}) cannot be removed."]]);
            }
            $line->delete();
        }
    }

    private function total(array $items): string
    {
        return Money::add(...array_map(
            fn (array $item): string => Money::multiply(Money::normalize($item['unit_price']), $item['quantity']),
            $items,
        ));
    }

    private function nextNumber(): string
    {
        $prefix = 'PO-'.now('Europe/Tirane')->format('Y').'-';
        $sequence = PurchaseOrder::query()->where('po_number', 'like', $prefix.'%')->count() + 1;
        do {
            $number = $prefix.str_pad((string) $sequence++, 4, '0', STR_PAD_LEFT);
        } while (PurchaseOrder::query()->where('po_number', $number)->exists());

        return $number;
    }

    private function snapshot(PurchaseOrder $order): array
    {
        return [
            'supplier_id' => $order->supplier_id, 'warehouse_id' => $order->warehouse_id, 'status' => $order->status, 'total_amount' => Money::normalize($order->total_amount), 'total_amount_eur' => Money::normalize($order->total_amount_eur),
            'currency' => $order->currency, 'exchange_rate' => (string) $order->exchange_rate, 'exchange_rate_date' => $order->exchange_rate_date?->toDateString(), 'ordered_at' => $order->ordered_at?->toDateString(), 'expected_at' => $order->expected_at?->toDateString(),
            'due_at' => $order->due_at?->toDateString(), 'notes' => $order->notes,
            'items' => $order->items->map(fn ($item) => ['id' => $item->id, 'product_id' => $item->product_id, 'description' => $item->description, 'unit' => $item->unit, 'quantity' => (string) $item->quantity, 'unit_price' => Money::normalize($item->unit_price)])->values()->all(),
        ];
    }

    private function audit(PurchaseOrder $order, string $action, ?array $old, ?array $new, ?string $reason): void
    {
        PurchaseOrderChange::create(['company_id' => $order->company_id, 'purchase_order_id' => $order->id, 'user_id' => Auth::id(), 'action' => $action, 'reason' => $reason, 'old_values' => $old, 'new_values' => $new]);
    }

    private function currencySnapshot(string $currency, string $total, array $data): array
    {
        $currency = strtoupper($currency);
        $rate = Money::normalizeDecimal($currency === 'EUR' ? '1' : ($data['exchange_rate'] ?? '0'), 6);
        if (Money::compareDecimal($rate, '0') <= 0) {
            throw ValidationException::withMessages(['exchange_rate' => ['Enter the historical conversion rate from the order currency to EUR.']]);
        }

        return [
            'exchange_rate' => $rate,
            'exchange_rate_date' => $data['exchange_rate_date'] ?? ($currency === 'EUR' ? ($data['ordered_at'] ?? now()->toDateString()) : null),
            'exchange_rate_source' => $data['exchange_rate_source'] ?? ($currency === 'EUR' ? 'EUR base currency' : null),
            'total_amount_eur' => Money::multiply($total, $rate),
        ];
    }

    private function nextReceiptNumber(int $companyId): string
    {
        $prefix = 'GR-'.now('Europe/Tirane')->format('Y').'-';
        $sequence = GoodsReceipt::withoutGlobalScopes()->where('company_id', $companyId)->where('receipt_number', 'like', $prefix.'%')->count() + 1;
        do {
            $number = $prefix.str_pad((string) $sequence++, 5, '0', STR_PAD_LEFT);
        } while (GoodsReceipt::withoutGlobalScopes()->where('company_id', $companyId)->where('receipt_number', $number)->exists());

        return $number;
    }

    private function assertStatusTransition(string $from, string $to): void
    {
        $allowed = [
            'draft' => ['confirmed', 'ordered'],
            'confirmed' => ['draft', 'ordered'],
            'ordered' => ['confirmed'],
            'received' => ['completed'],
        ];

        if (! in_array($to, $allowed[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => ["Purchase Order status cannot change from {$from} to {$to}."],
            ]);
        }
    }
}
