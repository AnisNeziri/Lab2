<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierMatchEvent;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierInvoiceMatchingService
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly SupplierPaymentAllocationService $paymentAllocations,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $query = Expense::query()->whereNotNull('supplier_id')->where('document_type', 'purchase_invoice')
            ->with(['supplier:id,name', 'purchaseOrder:id,po_number,status', 'supplierInvoiceItems.product:id,name,sku'])
            ->withSum(['payments as payments_sum_amount' => fn ($q) => $q->where('status', 'completed')], 'amount')
            ->withSum('purchaseOrderPaymentAllocations', 'amount')
            ->withSum('supplierCredits', 'gross_amount');
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                ->orWhere('vendor_name', 'like', "%{$search}%"));
        }
        if (! empty($filters['match_status'])) {
            $query->where('match_status', $filters['match_status']);
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        return $query->latest('invoice_date')->latest('id')->paginate($filters['per_page'] ?? 20);
    }

    public function show(Expense $expense): Expense
    {
        abort_unless($expense->supplier_id && $expense->document_type === 'purchase_invoice', 404);
        $expense = $expense->load([
            'supplier', 'purchaseOrder.items.product', 'goodsReceipts.items.product',
            'supplierInvoiceItems.product', 'supplierInvoiceItems.purchaseOrderItem',
            'supplierInvoiceItems.goodsReceiptItem', 'matchEvents' => fn ($q) => $q->with('user:id,name')->latest('id'),
            'payments', 'purchaseOrderPaymentAllocations.payment',
            'purchaseOrder.payments' => fn ($query) => $query->with('allocations')->oldest('payment_date')->oldest('id'),
        ]);
        $expense->setAttribute('allocation_history', DB::table('supplier_payment_allocation_requests')->where('company_id', $expense->company_id)->where('expense_id', $expense->id)->orderByDesc('id')->limit(100)->get());
        $expense->syncOriginalAttribute('allocation_history');

        return $expense;
    }

    public function create(array $data): Expense
    {
        return DB::transaction(function () use ($data) {
            $supplier = Supplier::query()->findOrFail($data['supplier_id']);
            $po = ! empty($data['purchase_order_id']) ? PurchaseOrder::query()->with('items')->findOrFail($data['purchase_order_id']) : null;
            if ($po && (int) $po->supplier_id !== (int) $supplier->id) {
                throw ValidationException::withMessages(['purchase_order_id' => ['The Purchase Order belongs to a different supplier.']]);
            }
            $this->validatePurchaseOrderCurrency($po, (string) $data['currency']);
            $this->validateDocumentTotals($data);
            $expenseData = $data;
            unset($expenseData['items'], $expenseData['goods_receipt_ids'], $expenseData['supplier_id'], $expenseData['purchase_order_id']);
            $expenseData['vendor_name'] = $expenseData['vendor_name'] ?: $supplier->name;
            $expense = $this->expenses->create($expenseData)->fresh();
            $expense->update([
                'supplier_id' => $supplier->id, 'purchase_order_id' => $po?->id,
                'match_status' => 'unmatched', 'updated_by' => Auth::id(),
            ]);
            $this->syncReceipts($expense, $data['goods_receipt_ids'] ?? [], $po);
            $this->replaceLines($expense, $data['items'], $po);
            $this->recalculate($expense);
            $this->event($expense, 'created', null, $expense->fresh()->toArray());
            $this->paymentAllocations->autoAllocateForInvoice($expense->fresh());

            return $this->show($expense->fresh());
        });
    }

    public function update(Expense $expense, array $data): Expense
    {
        return DB::transaction(function () use ($expense, $data) {
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            abort_unless($locked->supplier_id, 404);
            if ($locked->approved_at || $locked->status !== 'draft') {
                throw ValidationException::withMessages(['supplier_invoice' => ['Only an unapproved draft supplier invoice can be edited.']]);
            }
            $old = $this->show($locked)->toArray();
            $supplier = Supplier::query()->findOrFail($data['supplier_id']);
            $po = ! empty($data['purchase_order_id']) ? PurchaseOrder::query()->with('items')->findOrFail($data['purchase_order_id']) : null;
            if ($po && (int) $po->supplier_id !== (int) $supplier->id) {
                throw ValidationException::withMessages(['purchase_order_id' => ['The Purchase Order belongs to a different supplier.']]);
            }
            $this->validatePurchaseOrderCurrency($po, (string) $data['currency']);
            if ($locked->purchaseOrderPaymentAllocations()->exists()
                && ((int) $locked->purchase_order_id !== (int) ($po?->id ?? 0)
                    || strtoupper((string) $locked->currency) !== strtoupper((string) $data['currency']))) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => ['A supplier invoice with allocated Purchase Order payments cannot be moved to another order or currency.'],
                ]);
            }
            $this->validateDocumentTotals($data);
            $expenseData = $data;
            unset($expenseData['items'], $expenseData['goods_receipt_ids'], $expenseData['supplier_id'], $expenseData['purchase_order_id']);
            $updated = $this->expenses->update($locked, $expenseData)->fresh();
            $updated->update(['supplier_id' => $supplier->id, 'purchase_order_id' => $po?->id]);
            $this->syncReceipts($updated, $data['goods_receipt_ids'] ?? [], $po);
            $this->replaceLines($updated, $data['items'], $po);
            $this->recalculate($updated);
            $this->event($updated, 'updated', $old, $this->show($updated->fresh())->toArray());
            $this->paymentAllocations->autoAllocateForInvoice($updated->fresh());

            return $this->show($updated->fresh());
        });
    }

    public function recalculate(Expense $expense): Expense
    {
        return DB::transaction(function () use ($expense) {
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($locked->approved_at || $locked->status === 'reversed') {
                return $this->show($locked);
            }
            $summary = $this->calculateSummary($locked);
            $old = $locked->only(['match_status', 'match_summary']);
            $locked->update(['match_status' => $summary['status'], 'match_summary' => $summary]);
            if ($old !== $locked->only(['match_status', 'match_summary'])) {
                $this->event($locked, 'recalculated', $old, $locked->only(['match_status', 'match_summary']));
            }

            return $this->show($locked->fresh());
        });
    }

    public function approve(Expense $expense, ?string $reason): Expense
    {
        return DB::transaction(function () use ($expense, $reason) {
            DB::table('companies')->where('id', Auth::user()->company_id)->lockForUpdate()->first();
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($locked->approved_at) {
                return $this->show($locked);
            }
            if(!in_array($locked->status,['draft','posted'],true))throw ValidationException::withMessages(['supplier_invoice'=>'Only an active draft or posted bill can be approved. A reversal remains part of its history.']);
            $locked = $this->recalculate($locked);
            if (! empty($locked->match_summary['approval_blocked'])) {
                throw ValidationException::withMessages(['items' => ['Resolve the conflicting receipt allocation or unit of measure before posting this bill.']]);
            }
            if (in_array($locked->match_status, ['exception', 'unmatched'], true) && blank($reason)) {
                throw ValidationException::withMessages(['reason' => ['Explain why this supplier invoice can be approved despite matching exceptions.']]);
            }
            if ($locked->status === 'draft') {
                $this->expenses->post($locked, true);
            }
            $locked->refresh()->update([
                'match_status' => 'approved', 'approved_at' => now(), 'approved_by' => Auth::id(),
                'match_summary' => [...($locked->match_summary ?? []), 'approved_exception_reason' => $reason],
            ]);
            $this->paymentAllocations->autoAllocateForInvoice($locked->fresh());
            $this->event($locked, 'approved', null, $locked->only(['match_status', 'approved_at', 'approved_by']), $reason);

            return $this->show($locked->fresh());
        });
    }

    public function allocatePayment(Expense $expense, int $paymentId, mixed $amount, ?string $reason, string $idempotencyKey): Expense
    {
        $payment = PurchaseOrderPayment::query()->findOrFail($paymentId);
        $this->paymentAllocations->allocate($payment, $expense, $amount, $reason, $idempotencyKey);

        return $this->show($expense->fresh());
    }

    private function replaceLines(Expense $expense, array $lines, ?PurchaseOrder $po): void
    {
        $expense->supplierInvoiceItems()->delete();
        $linkedReceiptIds = $expense->goodsReceipts()->pluck('goods_receipts.id')->map(fn ($id) => (int) $id);
        foreach ($lines as $line) {
            $poItem = ! empty($line['purchase_order_item_id']) ? PurchaseOrderItem::query()->findOrFail($line['purchase_order_item_id']) : null;
            if ($poItem && (! $po || (int) $poItem->purchase_order_id !== (int) $po->id)) {
                throw ValidationException::withMessages(['items' => ['A selected PO line does not belong to this Purchase Order.']]);
            }
            $receiptItem = ! empty($line['goods_receipt_item_id']) ? GoodsReceiptItem::query()->with('receipt')->findOrFail($line['goods_receipt_item_id']) : null;
            if ($receiptItem && (! $po || (int) $receiptItem->receipt->purchase_order_id !== (int) $po->id)) {
                throw ValidationException::withMessages(['items' => ['A selected receipt line does not belong to this Purchase Order.']]);
            }
            if ($receiptItem && ! $linkedReceiptIds->contains((int) $receiptItem->goods_receipt_id)) {
                throw ValidationException::withMessages(['items' => ['A selected receipt line is not part of the receipts linked to this supplier invoice.']]);
            }
            if ($poItem && $receiptItem && (int) $receiptItem->purchase_order_item_id !== (int) $poItem->id) {
                throw ValidationException::withMessages(['items' => ['The selected receipt line does not belong to the selected Purchase Order line.']]);
            }
            $poItem ??= $receiptItem?->purchaseOrderItem;
            $sourceProductId = $poItem?->product_id ?? $receiptItem?->product_id;
            if ($sourceProductId && ! empty($line['product_id']) && (int) $line['product_id'] !== (int) $sourceProductId) {
                throw ValidationException::withMessages(['items' => ['The selected product does not match its Purchase Order or receipt line.']]);
            }
            $receiptItemId = $receiptItem?->id;
            if (! $receiptItemId && $poItem && $linkedReceiptIds->isNotEmpty()) {
                $candidates = $poItem->receiptItems()->whereIn('goods_receipt_id', $linkedReceiptIds)->limit(2)->pluck('id');
                $receiptItemId = $candidates->count() === 1 ? $candidates->first() : null;
            }
            [$quantity, $unitPrice, $vatRate, $net, $vat] = $this->lineAmounts($line);
            SupplierInvoiceItem::create([
                ...$line, 'company_id' => $expense->company_id, 'expense_id' => $expense->id,
                'purchase_order_item_id' => $poItem?->id, 'goods_receipt_item_id' => $receiptItemId,
                'product_id' => $line['product_id'] ?? $poItem?->product_id ?? $receiptItem?->product_id,
                'quantity' => $quantity, 'unit_price' => $unitPrice, 'vat_rate' => $vatRate,
                'net_amount' => $net, 'vat_amount' => $vat, 'total_amount' => Money::add($net, $vat),
            ]);
        }
    }

    private function syncReceipts(Expense $expense, array $ids, ?PurchaseOrder $po): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids && ! $po) {
            throw ValidationException::withMessages(['purchase_order_id' => ['Select the Purchase Order for the linked goods receipts.']]);
        }
        if ($ids) {
            $receipts = GoodsReceipt::query()->whereIn('id', $ids)->get();
            if ($receipts->count() !== count($ids) || ($po && $receipts->contains(fn ($receipt) => (int) $receipt->purchase_order_id !== (int) $po->id))) {
                throw ValidationException::withMessages(['goods_receipt_ids' => ['All selected receipts must belong to this company and Purchase Order.']]);
            }
        }
        $expense->goodsReceipts()->sync($ids);
    }

    private function calculateSummary(Expense $expense): array
    {
        $company = Auth::user()->company;
        $quantityTolerance = (float) ($company->supplier_match_quantity_tolerance ?? config('procurement.matching.quantity_tolerance', .001));
        $priceTolerance = (float) ($company->supplier_match_price_tolerance ?? config('procurement.matching.unit_price_tolerance', .02));
        $taxTolerance = (float) ($company->supplier_match_tax_tolerance ?? config('procurement.matching.tax_tolerance', .02));
        $expense->load(['supplierInvoiceItems.purchaseOrderItem', 'purchaseOrder', 'goodsReceipts:id']);
        $linkedReceiptIds = $expense->goodsReceipts->pluck('id');
        $orderLineIds = $expense->supplierInvoiceItems->pluck('purchase_order_item_id')->filter()->unique();
        $receiptLines = GoodsReceiptItem::query()->whereIn('purchase_order_item_id', $orderLineIds)
            ->whereHas('receipt', fn ($q) => $q->where('company_id', $expense->company_id))
            ->get(['id', 'goods_receipt_id', 'purchase_order_item_id', 'ordered_unit', 'accepted_quantity'])
            ->groupBy('purchase_order_item_id');
        $postedLines = SupplierInvoiceItem::query()->whereIn('purchase_order_item_id', $orderLineIds)
            ->where('expense_id', '!=', $expense->id)->whereHas('expense', fn ($q) => $q->where('status', 'posted'))
            ->with('expense.goodsReceipts:id')->get()->groupBy('purchase_order_item_id');
        $receiptFor = function (SupplierInvoiceItem $line, $receiptIds) use ($receiptLines) {
            $candidates = $receiptLines->get($line->purchase_order_item_id, collect())->whereIn('goods_receipt_id', $receiptIds);
            if ($line->goods_receipt_item_id) {
                return $candidates->firstWhere('id', $line->goods_receipt_item_id);
            }

            return $candidates->count() === 1 ? $candidates->first() : null;
        };
        $currentByReceipt = $expense->supplierInvoiceItems->groupBy(fn ($line) => $receiptFor($line, $linkedReceiptIds)?->id ?? 0);
        $currentByOrder = $expense->supplierInvoiceItems->groupBy('purchase_order_item_id');
        $lines = [];
        $hasException = false;
        $hasPartial = false;
        $approvalBlocked = false;
        $expectedRate = in_array($expense->vat_treatment, ['standard', 'reduced'], true) ? $expense->vat_rate : 0;
        foreach ($expense->supplierInvoiceItems as $line) {
            $poItem = $line->purchaseOrderItem;
            $receiptLine = $receiptFor($line, $linkedReceiptIds);
            $priorOnOrder = $postedLines->get($line->purchase_order_item_id, collect());
            $priorByReceipt = $priorOnOrder->groupBy(fn ($prior) => $receiptFor($prior, $prior->expense->goodsReceipts->pluck('id'))?->id ?? 0);
            $received = (float) ($receiptLine?->accepted_quantity ?? 0);
            $priorOnReceipt = $receiptLine ? $priorByReceipt->get($receiptLine->id, collect()) : collect();
            $otherInvoiced = (float) $priorOnReceipt->sum('quantity');
            $priorOrderQuantity = (float) $priorOnOrder->sum('quantity');
            $ordered = (float) ($poItem?->quantity ?? 0);
            $invoiced = (float) $line->quantity;
            $receiptClaims = $receiptLine ? $currentByReceipt->get($receiptLine->id) : collect([$line]);
            $quantityVariance = round($otherInvoiced + $receiptClaims->sum('quantity') - $received, 3);
            $priceVariance = round((float) $line->unit_price - (float) ($poItem?->unit_price ?? $line->unit_price), 4);
            $taxVariance = Money::subtract($line->vat_amount, Money::product([$line->net_amount, $expectedRate, '0.01']));
            $issues = [];
            $blocked = false;
            if (! $poItem) {
                $issues[] = 'invoice_line_without_po';
            }
            if ($poItem && $received <= $quantityTolerance) {
                $needsReceiptLine = $receiptLine === null && $linkedReceiptIds->isNotEmpty();
                $issues[] = $needsReceiptLine ? 'receipt_line_required' : 'invoice_without_receipt';
                $blocked = $needsReceiptLine;
            }
            if ($receiptLine && $priorByReceipt->has(0)) {
                $issues[] = 'prior_bill_receipt_unverified';
            }
            if ($poItem && (strcasecmp(trim($line->unit), trim($poItem->unit)) !== 0
                || ($receiptLine && $receiptLine->ordered_unit && strcasecmp(trim($line->unit), trim($receiptLine->ordered_unit)) !== 0))) {
                $issues[] = 'unit_mismatch';
                $blocked = true;
            }
            if ($poItem && $received > $ordered + $quantityTolerance) {
                $issues[] = 'receipt_exceeds_po';
            }
            if ($quantityVariance > $quantityTolerance) {
                $issues[] = 'invoiced_quantity_exceeds_received';
                if ($receiptLine && ($priorOnReceipt->isNotEmpty() || $receiptClaims->count() > 1)) {
                    $issues[] = 'receipt_allocation_conflict';
                    $blocked = true;
                }
            }
            if ($poItem && $priorOrderQuantity > 0 && round($priorOrderQuantity + $currentByOrder->get($poItem->id)->sum('quantity') - $ordered, 3) > $quantityTolerance) {
                $issues[] = 'previous_billing_exceeds_order';
                $blocked = true;
            }
            if (abs($priceVariance) > $priceTolerance) {
                $issues[] = 'unit_price_variance';
            }
            if (abs((float) $taxVariance) > $taxTolerance || Money::compareDecimal($line->vat_rate, $expectedRate) !== 0) {
                $issues[] = 'tax_variance';
            }
            $approvalBlocked = $approvalBlocked || $blocked;
            if ($issues) {
                $hasException = true;
            }
            if ($poItem && $receiptLines->get($poItem->id, collect())->sum('accepted_quantity') + $quantityTolerance < $ordered) {
                $hasPartial = true;
            }
            $priceVarianceTotal = Money::multiply((string) $priceVariance, (string) $invoiced);
            $matchFlags = array_values(array_filter([! $poItem ? 'NO_PO' : null, $poItem && $received <= $quantityTolerance ? 'NO_RECEIPT' : null, $quantityVariance > $quantityTolerance ? 'QUANTITY_VARIANCE' : null, abs($priceVariance) > $priceTolerance ? 'PRICE_VARIANCE' : null]));
            $variance = ['priceVarianceTotal' => $priceVarianceTotal, 'matchFlags' => $matchFlags, 'receipt_item_id' => $receiptLine?->id, 'approval_blocked' => $blocked, ...compact('ordered', 'received', 'otherInvoiced', 'invoiced', 'quantityVariance', 'priceVariance', 'taxVariance', 'issues')];
            $line->update(['variance' => $variance]);
            $lines[] = ['line_id' => $line->id, ...$variance];
        }
        $status = ! $expense->purchase_order_id ? 'unmatched' : ($hasException ? 'exception' : ($hasPartial ? 'partially_matched' : 'matched'));

        return [
            'status' => $status, 'calculated_at' => now()->toIso8601String(), 'lines' => $lines,
            'approval_blocked' => $approvalBlocked,
            'tolerances' => ['quantity' => $quantityTolerance, 'unit_price' => $priceTolerance, 'tax' => $taxTolerance],
            'match_label' => ! $expense->purchase_order_id ? 'NO_PO' : ($hasException ? 'NEEDS_REVIEW' : ($hasPartial ? 'PARTIAL_MATCH' : 'MATCHED')),
            'price_variance_total' => Money::normalize(collect($lines)->sum('priceVarianceTotal')),
            'ordered_total' => round((float) ($expense->purchaseOrder?->total_amount ?? 0), 2),
            'invoice_total' => round((float) $expense->gross_amount, 2),
        ];
    }

    private function validateDocumentTotals(array $data): void
    {
        $amounts = array_map(fn ($line) => $this->lineAmounts($line), $data['items']);
        $netCents = array_sum(array_map(fn ($line) => Money::minor($line[3]), $amounts));
        $vatCents = array_sum(array_map(fn ($line) => Money::minor($line[4]), $amounts));
        if (abs($netCents - Money::minor($data['net_amount'])) > 2 || abs($vatCents - Money::minor($data['vat_amount'])) > 2) {
            throw ValidationException::withMessages(['items' => ['Supplier invoice line totals must equal the document net and VAT totals.']]);
        }
    }

    private function lineAmounts(array $line): array
    {
        $quantity = Money::normalizeDecimal($line['quantity'], 3);
        $price = Money::normalizeDecimal($line['unit_price'], 4);
        $rate = Money::normalizeDecimal($line['vat_rate'] ?? 0, 3);
        $net = Money::multiply($quantity, $price);

        return [$quantity, $price, $rate, $net, Money::product([$net, $rate, '0.01'])];
    }

    private function validatePurchaseOrderCurrency(?PurchaseOrder $purchaseOrder, string $currency): void
    {
        if ($purchaseOrder && strtoupper((string) $purchaseOrder->currency) !== strtoupper($currency)) {
            throw ValidationException::withMessages([
                'currency' => ['The supplier invoice currency must match its Purchase Order currency.'],
            ]);
        }
    }

    private function event(Expense $expense, string $action, mixed $old, mixed $new, ?string $reason = null): void
    {
        SupplierMatchEvent::create([
            'company_id' => $expense->company_id, 'expense_id' => $expense->id,
            'action' => $action, 'old_values' => $old, 'new_values' => $new,
            'reason' => $reason, 'user_id' => Auth::id(), 'created_at' => now(),
        ]);
    }
}
