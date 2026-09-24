<?php

namespace App\Services;

use App\Models\DailySaleItem;
use App\Models\Expense;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnEvent;
use App\Models\InventoryReturnItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\WarehouseLocation;
use App\Support\Money;
use App\Support\RequestFingerprint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryReturnService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly FinancialAccountService $accounts,
        private readonly CustomerDebtService $debts,
        private readonly ExpenseService $expenses,
        private readonly InventoryIntegrityService $integrity,
        private readonly OperationalAccountingService $operationalAccounting,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $query = InventoryReturn::query()->with(['customer:id,name', 'supplier:id,name', 'items.product:id,name,sku,unit']);
        if (! empty($filters['type'])) $query->where('type', $filters['type']);
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('return_number', 'like', "%{$search}%")
                ->orWhere('reference_number', 'like', "%{$search}%")
                ->orWhere('reason', 'like', "%{$search}%"));
        }
        return $query->latest('id')->paginate($filters['per_page'] ?? 20);
    }

    public function show(InventoryReturn $return): InventoryReturn
    {
        return $return->load([
            'customer', 'supplier', 'dailySale', 'purchaseOrder', 'goodsReceipt',
            'financialAccount', 'supplierCreditExpense', 'items.product', 'items.warehouse',
            'items.location', 'items.dailySaleItem', 'items.goodsReceiptItem',
            'events' => fn ($q) => $q->latest('id'),
        ]);
    }

    public function create(array $data): InventoryReturn
    {
        return DB::transaction(function () use ($data) {
            $this->validateHeader($data);
            $key = (string) ($data['idempotency_key'] ?? Str::uuid());
            $fingerprint = RequestFingerprint::make($data, ['idempotency_key']);
            $companyId = (int) Auth::user()->company_id;
            // Serializes return-number assignment and makes the idempotency
            // lookup deterministic under simultaneous submissions.
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            if ($existing = InventoryReturn::query()->where('idempotency_key', $key)->first()) {
                if (! $existing->request_fingerprint
                    || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This idempotency key was already used for different return details.'],
                    ]);
                }

                return $this->show($existing);
            }
            $return = InventoryReturn::create([
                ...$this->header($data), 'company_id' => $companyId,
                'return_number' => $this->nextNumber($data['type']), 'status' => 'draft',
                'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
                'created_by' => Auth::id(),
            ]);
            $this->replaceItems($return, $data['items']);
            $this->validateFinancialAmount($return);
            $this->event($return, 'created', null, $return->fresh()->toArray());
            return $this->show($return->fresh());
        });
    }

    public function update(InventoryReturn $return, array $data): InventoryReturn
    {
        return DB::transaction(function () use ($return, $data) {
            $locked = InventoryReturn::query()->lockForUpdate()->findOrFail($return->id);
            $this->ensureDraft($locked);
            $this->validateHeader($data);
            $old = $this->show($locked)->toArray();
            $locked->update($this->header($data));
            $this->replaceItems($locked, $data['items']);
            $this->validateFinancialAmount($locked);
            $this->event($locked, 'updated', $old, $this->show($locked->fresh())->toArray());
            return $this->show($locked->fresh());
        });
    }

    public function delete(InventoryReturn $return): void
    {
        DB::transaction(function () use ($return) {
            $locked = InventoryReturn::query()->lockForUpdate()->findOrFail($return->id);
            $this->ensureDraft($locked);
            $locked->delete();
        });
    }

    public function submit(InventoryReturn $return): InventoryReturn
    {
        return DB::transaction(function () use ($return) {
            $locked = InventoryReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($locked->status === 'submitted') return $this->show($locked);
            $this->ensureDraft($locked);
            $this->validateReturnableQuantities($locked);
            $old = $locked->toArray();
            $locked->update(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => Auth::id()]);
            $this->event($locked, 'submitted', $old, $locked->fresh()->toArray());
            return $this->show($locked->fresh());
        });
    }

    public function approve(InventoryReturn $return): InventoryReturn
    {
        return DB::transaction(function () use ($return) {
            $locked = InventoryReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($locked->status === 'approved') return $this->show($locked);
            if ($locked->status !== 'submitted') throw ValidationException::withMessages(['status' => ['Only a submitted return can be approved.']]);
            $this->validateReturnableQuantities($locked);
            $old = $locked->toArray();
            $locked->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => Auth::id()]);
            $this->event($locked, 'approved', $old, $locked->fresh()->toArray());
            return $this->show($locked->fresh());
        });
    }

    public function complete(InventoryReturn $return): InventoryReturn
    {
        return DB::transaction(function () use ($return) {
            $locked = InventoryReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($locked->status === 'completed') return $this->show($locked);
            if ($locked->status !== 'approved') throw ValidationException::withMessages(['status' => ['Approve the return before completing it.']]);
            $this->validateReturnableQuantities($locked, true);
            $this->validateFinancialAmount($locked);
            $old = $locked->toArray();
            foreach ($locked->items()->with('product')->lockForUpdate()->get() as $item) {
                $this->processStock($locked, $item);
                $item->update(['processed_quantity' => $item->quantity]);
            }
            $this->processFinancialResolution($locked);
            $locked->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => Auth::id()]);
            if ($locked->type==='customer' && $locked->daily_sale_id) {
                $invoice=\App\Models\Invoice::where('daily_sale_id',$locked->daily_sale_id)->where('document_type','invoice')->whereNotNull('issued_at')->where('status','!=','void')->first();
                if($invoice)app(InvoiceService::class)->creditOrderReturn($invoice,$locked);
            }
            $this->event($locked, 'completed', $old, $locked->fresh()->toArray());
            return $this->show($locked->fresh());
        });
    }

    public function cancel(InventoryReturn $return, string $reason): InventoryReturn
    {
        return DB::transaction(function () use ($return, $reason) {
            $locked = InventoryReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($locked->status === 'completed') throw ValidationException::withMessages(['status' => ['A completed return cannot be cancelled; create a controlled reversal.']]);
            if ($locked->status === 'cancelled') return $this->show($locked);
            $old = $locked->toArray();
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => Auth::id()]);
            $this->event($locked, 'cancelled', $old, $locked->fresh()->toArray(), $reason);
            return $this->show($locked->fresh());
        });
    }

    private function processStock(InventoryReturn $return, InventoryReturnItem $item): void
    {
        $state = $item->stock_state ?: match ($item->condition) {
            'sellable' => 'available', 'quarantine' => 'quarantine', default => 'damaged',
        };
        $common = [
            'product_id' => $item->product_id, 'warehouse_id' => $item->warehouse_id,
            'location_id' => $item->location_id, 'quantity' => (float) $item->quantity,
            'stock_state' => $state, 'reason' => $return->reason,
            'source_type' => 'inventory_return', 'source_id' => $return->id,
            // A customer item received directly as scrap enters and leaves the
            // warehouse ledger for audit, but must not distort company stock
            // quantity or weighted-average valuation.
            'affects_company_quantity' => ! ($return->type === 'customer' && $item->condition === 'scrap'),
            'metadata' => ['return_number' => $return->return_number, 'return_item_id' => $item->id, 'trace_data' => $item->trace_data],
            'trace_allocations' => $this->returnTraceAllocations($item),
        ];
        if ($return->type === 'customer') {
            $this->stock->store([
                ...$common,
                'type' => 'in',
                'movement_code' => 'customer_return',
                'final_unit_cost' => $item->unit_cost,
                'idempotency_key' => $return->idempotency_key.'-stock-'.$item->id,
            ]);
            if ($item->condition === 'scrap') {
                $this->stock->store([...$common, 'type' => 'out', 'movement_code' => 'damage_writeoff', 'idempotency_key' => $return->idempotency_key.'-scrap-'.$item->id]);
                $this->integrity->assertProductReconciled(Product::query()->findOrFail($item->product_id));
            }
        } else {
            $this->stock->store([...$common, 'type' => 'out', 'movement_code' => 'supplier_return', 'idempotency_key' => $return->idempotency_key.'-stock-'.$item->id]);
        }
    }

    private function returnTraceAllocations(InventoryReturnItem $item): array
    {
        $trace = $item->trace_data ?? [];
        if (isset($trace['allocations']) && is_array($trace['allocations'])) {
            return array_values($trace['allocations']);
        }
        if (array_is_list($trace)) {
            return $trace;
        }

        return isset($trace['inventory_lot_id']) || isset($trace['lot_number']) || isset($trace['serial_number'])
            ? [$trace]
            : [];
    }

    private function processFinancialResolution(InventoryReturn $return): void
    {
        $return->loadMissing(['customer', 'supplier']);
        $amount = round((float) $return->financial_amount, 2);
        if ($return->financial_resolution === 'none' || $return->financial_resolution === 'replacement') return;
        if ($amount <= 0) throw ValidationException::withMessages(['financial_amount' => ['Enter the amount for the selected financial resolution.']]);
        if ($return->financial_resolution === 'cash_refund') {
            if (! $return->financial_account_id) throw ValidationException::withMessages(['financial_account_id' => ['Choose the cash/bank account used for the refund.']]);
            $transaction = $this->accounts->post($return->financial_account_id, [
                'type' => $return->type === 'customer' ? 'refund_out' : 'refund_in',
                'amount' => $amount, 'transaction_date' => now()->toDateString(),
                'source_type' => 'inventory_return', 'source_id' => $return->id,
                'counterparty' => $return->type === 'customer' ? $return->customer?->name : $return->supplier?->name,
                'reference_number' => $return->reference_number, 'description' => $return->reason,
                'idempotency_key' => $return->idempotency_key.'-refund',
            ]);
            $return->update(['financial_account_transaction_id' => $transaction->id]);
            $this->operationalAccounting->postInventoryReturnRefund($return->fresh());
            return;
        }
        if ($return->financial_resolution === 'debt_credit') {
            if ($return->type !== 'customer' || ! $return->customer) throw ValidationException::withMessages(['financial_resolution' => ['Debt credit is available only for a customer return.']]);
            $debtTransaction = $this->debts->recordPayment($return->customer, [
                'amount' => $amount, 'transaction_date' => now()->toDateString(), 'source' => 'customer_return',
                'reference_number' => $return->return_number, 'note' => $return->reason,
                'idempotency_key' => $return->idempotency_key.'-debt-credit',
                'metadata' => ['inventory_return_id' => $return->id],
            ]);
            $return->update(['customer_debt_transaction_id' => $debtTransaction->id]);
            return;
        }
        if ($return->financial_resolution === 'supplier_credit') $this->createSupplierCredit($return, $amount);
    }

    private function createSupplierCredit(InventoryReturn $return, float $amount): void
    {
        if ($return->type !== 'supplier') throw ValidationException::withMessages(['financial_resolution' => ['Supplier credit is available only for a supplier return.']]);
        $original = Expense::query()->where('purchase_order_id', $return->purchase_order_id)
            ->where('supplier_id', $return->supplier_id)->where('status', 'posted')->latest('id')->first();
        if (! $original) throw ValidationException::withMessages(['purchase_order_id' => ['Link a posted supplier invoice before issuing a supplier credit.']]);
        $gross = max(.01, $amount);
        $existingCredits = (float) $original->supplierCredits()->sum('gross_amount');
        if ($existingCredits + $gross > (float) $original->gross_amount + .01) {
            throw ValidationException::withMessages(['financial_amount' => ['Supplier credits cannot exceed the original supplier invoice total.']]);
        }
        $ratio = (float) $original->gross_amount > 0 ? min(1, $gross / (float) $original->gross_amount) : 1;
        $vat = round((float) $original->vat_amount * $ratio, 2);
        $net = round($gross - $vat, 2);
        $credit = $this->expenses->create([
            'vendor_name' => $original->vendor_name, 'vendor_business_number' => $original->vendor_business_number,
            'vendor_fiscal_number' => $original->vendor_fiscal_number, 'vendor_vat_number' => $original->vendor_vat_number,
            'document_type' => 'credit_note', 'document_number' => $return->reference_number ?: $return->return_number,
            'original_document_number' => $original->document_number, 'source_type' => $original->source_type,
            'asset_treatment' => $original->asset_treatment, 'category' => 'inventory',
            'description' => 'Supplier return '.$return->return_number, 'business_purpose' => null,
            'invoice_date' => now()->toDateString(), 'received_date' => now()->toDateString(), 'supply_date' => now()->toDateString(),
            'due_date' => null, 'currency' => $original->currency, 'exchange_rate' => $original->exchange_rate,
            'exchange_rate_date' => $original->exchange_rate_date?->toDateString(), 'exchange_rate_source' => $original->exchange_rate_source,
            'net_amount' => $net, 'vat_rate' => $original->vat_rate, 'vat_amount' => $vat,
            'vat_treatment' => $original->vat_treatment, 'input_vat_eligible' => $original->input_vat_eligible,
            'deductible_vat_amount' => round((float) $original->deductible_vat_amount * $ratio, 2),
            'tax_legal_reference' => $original->tax_legal_reference, 'notes' => $return->reason,
        ]);
        $credit->update([
            'supplier_id' => $return->supplier_id,
            'purchase_order_id' => $return->purchase_order_id,
            'original_expense_id' => $original->id,
        ]);
        $this->expenses->post($credit);
        $return->update(['supplier_credit_expense_id' => $credit->id]);
    }

    private function replaceItems(InventoryReturn $return, array $items): void
    {
        $return->items()->delete();
        foreach ($items as $line) {
            $product = Product::query()->findOrFail($line['product_id']);
            $saleItem = ! empty($line['daily_sale_item_id']) ? DailySaleItem::query()->findOrFail($line['daily_sale_item_id']) : null;
            $receiptItem = ! empty($line['goods_receipt_item_id'])
                ? GoodsReceiptItem::query()
                    ->with(['purchaseOrderItem', 'receipt.purchaseOrder'])
                    ->whereHas('receipt', fn ($query) => $query->where('company_id', $return->company_id))
                    ->findOrFail($line['goods_receipt_item_id'])
                : null;
            if ($saleItem && ((int) $saleItem->product_id !== (int) $product->id || ($return->daily_sale_id && (int) $saleItem->daily_sale_id !== (int) $return->daily_sale_id))) {
                throw ValidationException::withMessages(['items' => ['A sale line does not match the return product or sale.']]);
            }
            if ($receiptItem && ((int) $receiptItem->product_id !== (int) $product->id || ($return->goods_receipt_id && (int) $receiptItem->goods_receipt_id !== (int) $return->goods_receipt_id))) {
                throw ValidationException::withMessages(['items' => ['A receipt line does not match the return product or receipt.']]);
            }
            if ($return->type === 'customer' && $receiptItem) throw ValidationException::withMessages(['items' => ['Customer returns cannot reference supplier receipt lines.']]);
            if ($return->type === 'supplier' && $saleItem) throw ValidationException::withMessages(['items' => ['Supplier returns cannot reference customer sale lines.']]);
            if ($receiptItem && (int) $receiptItem->receipt?->purchaseOrder?->supplier_id !== (int) $return->supplier_id) {
                throw ValidationException::withMessages([
                    'items' => ['A supplier return line must come from a receipt issued by the selected supplier.'],
                ]);
            }
            if (! empty($line['location_id']) && ! WarehouseLocation::query()
                ->whereKey((int) $line['location_id'])
                ->where('warehouse_id', (int) $line['warehouse_id'])
                ->where('is_active', true)
                ->exists()) {
                throw ValidationException::withMessages([
                    'items' => ['A return location must be active and belong to its selected warehouse.'],
                ]);
            }
            $quantity = round((float) $line['quantity'], 3);
            $expectedState = match ($line['condition']) {
                'sellable' => 'available',
                'quarantine' => 'quarantine',
                default => 'damaged',
            };
            if (! empty($line['stock_state']) && $line['stock_state'] !== $expectedState) {
                throw ValidationException::withMessages([
                    'items' => ["Stock state {$line['stock_state']} does not match condition {$line['condition']}."],
                ]);
            }
            $unitCost = (float) ($saleItem?->unit_cost ?? $product->weighted_average_cost ?? $product->purchase_price ?? 0);
            $saleUnitValue = $saleItem && (float) $saleItem->base_quantity > 0 ? (float) $saleItem->line_total / (float) $saleItem->base_quantity : null;
            $receiptUnitValue = $receiptItem?->base_purchase_unit_cost !== null
                ? (float) $receiptItem->base_purchase_unit_cost
                : ($receiptItem?->purchaseOrderItem && (float) $receiptItem->purchaseOrderItem->conversion_factor > 0
                    ? ((float) $receiptItem->purchaseOrderItem->unit_price * (float) ($receiptItem->receipt?->purchaseOrder?->exchange_rate ?: 1))
                        / (float) $receiptItem->purchaseOrderItem->conversion_factor
                    : (float) ($receiptItem?->purchaseOrderItem?->unit_price ?? 0) * (float) ($receiptItem?->receipt?->purchaseOrder?->exchange_rate ?: 1));
            InventoryReturnItem::create([
                ...$line, 'inventory_return_id' => $return->id, 'quantity' => $quantity,
                'processed_quantity' => 0, 'unit' => $product->unit,
                'stock_state' => $expectedState, 'unit_cost' => $unitCost,
                'line_value' => round($quantity * ($return->type === 'customer' ? ($saleUnitValue ?? 0) : $receiptUnitValue), 2),
            ]);
        }
    }

    private function validateReturnableQuantities(InventoryReturn $return, bool $lock = false): void
    {
        $return->loadMissing('items.product');
        foreach ($return->items as $item) {
            if ($item->daily_sale_item_id) {
                $sourceQuery = DailySaleItem::query()->whereKey($item->daily_sale_item_id);
                $source = $lock ? $sourceQuery->lockForUpdate()->firstOrFail() : $sourceQuery->firstOrFail();
                $already = (float) InventoryReturnItem::query()->where('daily_sale_item_id', $source->id)->whereKeyNot($item->id)
                    ->whereHas('inventoryReturn', fn ($q) => $q->where('status', 'completed'))->sum('processed_quantity');
                if ($already + (float) $item->quantity > (float) $source->base_quantity + .0005) {
                    throw ValidationException::withMessages(['items' => ["Return quantity for {$item->product->name} exceeds the quantity sold."]]);
                }
            }
            if ($item->goods_receipt_item_id) {
                $sourceQuery = GoodsReceiptItem::query()
                    ->whereKey($item->goods_receipt_item_id)
                    ->whereHas('receipt', fn ($query) => $query->where('company_id', $return->company_id));
                $source = $lock ? $sourceQuery->lockForUpdate()->firstOrFail() : $sourceQuery->firstOrFail();
                $received = (float) $source->accepted_base_quantity + (float) $source->damaged_base_quantity;
                $already = (float) InventoryReturnItem::query()->where('goods_receipt_item_id', $source->id)->whereKeyNot($item->id)
                    ->whereHas('inventoryReturn', fn ($q) => $q->where('status', 'completed'))->sum('processed_quantity');
                if ($already + (float) $item->quantity > $received + .0005) {
                    throw ValidationException::withMessages(['items' => ["Return quantity for {$item->product->name} exceeds the quantity received."]]);
                }
            }
        }
    }

    private function validateFinancialAmount(InventoryReturn $return): void
    {
        if (in_array($return->financial_resolution, ['none', 'replacement'], true)) {
            return;
        }

        $amount = Money::normalize($return->financial_amount ?? 0);
        if (Money::compare($amount, '0.00') <= 0) {
            throw ValidationException::withMessages([
                'financial_amount' => ['Enter a positive amount for the selected financial resolution.'],
            ]);
        }
        $maximum = $return->items()->pluck('line_value')->reduce(
            fn (string $total, mixed $value): string => Money::add($total, $value ?? 0),
            '0.00',
        );
        if (Money::compare($amount, $maximum) > 0) {
            throw ValidationException::withMessages([
                'financial_amount' => ["The financial resolution cannot exceed the returned goods value of {$maximum} EUR."],
            ]);
        }
    }

    private function validateHeader(array $data): void
    {
        $type = $data['type'];
        $resolution = $data['financial_resolution'];
        $allowed = $type === 'customer' ? ['none', 'cash_refund', 'debt_credit'] : ['none', 'cash_refund', 'supplier_credit', 'replacement'];
        if (! in_array($resolution, $allowed, true)) throw ValidationException::withMessages(['financial_resolution' => ['The selected resolution is not valid for this return type.']]);
        if ($type === 'customer' && (! empty($data['supplier_id']) || ! empty($data['purchase_order_id']) || ! empty($data['goods_receipt_id']))) {
            throw ValidationException::withMessages(['type' => ['A customer return cannot reference supplier purchasing records.']]);
        }
        if ($type === 'supplier' && (! empty($data['customer_id']) || ! empty($data['daily_sale_id']))) {
            throw ValidationException::withMessages(['type' => ['A supplier return cannot reference customer sales records.']]);
        }
        if ($type === 'customer' && ! empty($data['daily_sale_id'])) {
            $valid = \App\Models\DailySale::query()->whereKey($data['daily_sale_id'])->where('customer_id', $data['customer_id'])->exists();
            if (! $valid) throw ValidationException::withMessages(['daily_sale_id' => ['The selected sale does not belong to this customer.']]);
        }
        if ($type === 'supplier' && ! empty($data['purchase_order_id'])) {
            $valid = PurchaseOrder::query()->whereKey($data['purchase_order_id'])->where('supplier_id', $data['supplier_id'])->exists();
            if (! $valid) throw ValidationException::withMessages(['purchase_order_id' => ['The selected Purchase Order does not belong to this supplier.']]);
        }
        if ($type === 'supplier' && ! empty($data['goods_receipt_id'])) {
            $valid = \App\Models\GoodsReceipt::query()
                ->whereKey($data['goods_receipt_id'])
                ->whereHas('purchaseOrder', function ($query) use ($data): void {
                    $query->where('supplier_id', $data['supplier_id']);
                    if (! empty($data['purchase_order_id'])) {
                        $query->whereKey($data['purchase_order_id']);
                    }
                })->exists();
            if (! $valid) throw ValidationException::withMessages(['goods_receipt_id' => ['The selected goods receipt does not belong to this supplier and Purchase Order.']]);
        }
    }

    private function header(array $data): array
    {
        return collect($data)->only([
            'type', 'customer_id', 'supplier_id', 'daily_sale_id', 'purchase_order_id', 'goods_receipt_id',
            'financial_resolution', 'financial_amount', 'currency', 'financial_account_id', 'payment_method',
            'reference_number', 'reason', 'notes',
        ])->all();
    }

    private function nextNumber(string $type): string
    {
        $prefix = $type === 'customer' ? 'CR' : 'SR';
        $year = now()->format('Y');
        $last = InventoryReturn::query()->where('return_number', 'like', "{$prefix}-{$year}-%")->lockForUpdate()->latest('id')->value('return_number');
        $sequence = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;
        return sprintf('%s-%s-%05d', $prefix, $year, $sequence);
    }

    private function ensureDraft(InventoryReturn $return): void
    {
        if ($return->status !== 'draft') throw ValidationException::withMessages(['status' => ['Only a draft return can be edited or deleted.']]);
    }

    private function event(InventoryReturn $return, string $action, mixed $old, mixed $new, ?string $reason = null): void
    {
        InventoryReturnEvent::create([
            'company_id' => $return->company_id, 'inventory_return_id' => $return->id,
            'user_id' => Auth::id(), 'action' => $action, 'old_values' => $old,
            'new_values' => $new, 'reason' => $reason, 'created_at' => now(),
        ]);
    }
}
