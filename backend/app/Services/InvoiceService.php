<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceProfile;
use App\Models\InvoiceSequence;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly StockMovementService $stockMovements,
        private readonly InvoiceProfileService $profiles,
        private readonly UnitConversionService $unitConversions,
        private readonly InventoryCostingService $costing,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $this->requireCompanyId();
        $query = Invoice::query()->with(['customer:id,name,business_name', 'originalInvoice:id,invoice_number']);

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($builder) => $builder
                ->where('invoice_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                // JSON is stored as text by SQLite and is string-coercible in
                // MySQL, keeping buyer NUI/fiscal/VAT search cross-database.
                ->orWhere('buyer_snapshot', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($customer) => $customer
                    ->where('business_registration_number', 'like', "%{$search}%")
                    ->orWhere('fiscal_number', 'like', "%{$search}%")
                    ->orWhere('vat_number', 'like', "%{$search}%")));
        }
        $paymentFilter = $filters['payment_status'] ?? null;
        if (! empty($filters['status'])) {
            if (! $paymentFilter && in_array($filters['status'], ['paid', 'partially_paid', 'unpaid', 'overdue'], true)) {
                $paymentFilter = $filters['status'];
            } else {
                $query->where('status', $filters['status']);
            }
        }
        if (! empty($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('invoice_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('invoice_date', '<=', $filters['date_to']);
        }

        $today = $this->businessNow()->toDateString();
        match ($paymentFilter) {
            'paid' => $query->where('status', 'issued')->where('payment_status', 'paid'),
            'partially_paid' => $query->where('status', 'issued')->where('payment_status', 'partially_paid'),
            'overdue' => $query->where('status', 'issued')
                ->whereIn('payment_status', ['unpaid', 'partially_paid'])
                ->whereDate('due_at', '<', $today),
            'unpaid' => $query->where('status', 'issued')->where('payment_status', 'unpaid')
                ->where(fn ($q) => $q->whereNull('due_at')->orWhereDate('due_at', '>=', $today)),
            default => null,
        };

        return $query->latest('invoice_date')->latest('id')->paginate($filters['per_page'] ?? 20);
    }

    public function find(Invoice $invoice): Invoice
    {
        $this->assertInvoiceCompany($invoice);
        $invoice->load([
            'customer', 'items.product:id,name,sku,unit,default_warehouse_id', 'items.product.units',
            'items.warehouse:id,name,code', 'items.location:id,name,code,path',
            'payments' => fn ($query) => $query->latest('payment_date')->latest('id'),
            'creator:id,name', 'issuer:id,name', 'voider:id,name',
            'originalInvoice:id,invoice_number,invoice_date,grand_total',
            'creditNotes:id,original_invoice_id,invoice_number,status,grand_total,issued_at',
        ]);
        $this->verifyIntegrity($invoice);

        return $invoice;
    }

    /**
     * Rebuild the internal checksum after a portable restore remaps tenant IDs.
     * This is intentionally not an API operation; it exists so the backup
     * importer can preserve the integrity verification of issued invoices.
     */
    public function recomputeIntegrityAfterPortableRestore(Invoice $invoice): void
    {
        $this->assertInvoiceCompany($invoice);
        if (! $invoice->issued_at || $invoice->compliance_status !== 'compliant_b2b') {
            return;
        }

        $invoice->load('items');
        $invoice->forceFill(['integrity_hash' => $this->integrityHash($invoice)])->save();
    }

    public function createDraft(array $data): Invoice
    {
        $this->requireCompanyId();

        return DB::transaction(function () use ($data) {
            $data = $this->applyProfileDefaults($data);
            $data = $this->persistBuyerIfRequested($data);
            $buyer = $this->draftBuyer($data);
            $invoice = Invoice::create([
                'company_id' => Auth::user()->company_id,
                'customer_id' => $data['customer_id'] ?? null,
                'invoice_number' => null,
                'customer_name' => $buyer['legal_name'] ?? null,
                'status' => 'draft',
                'document_type' => 'invoice',
                'compliance_status' => 'draft',
                'buyer_snapshot' => $buyer ?: null,
                'currency' => 'EUR',
                'invoice_date' => $data['invoice_date'],
                'issued_at' => null,
                'supply_date' => $data['supply_date'],
                'supply_time' => $data['supply_time'] ?? null,
                'due_at' => $data['due_date'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'notes' => $data['notes'] ?? null,
                // These values are externally supplied references only. AIMS
                // never manufactures an official fiscal code or receipt number.
                'fiscal_receipt_number' => $data['fiscal_receipt_number'] ?? null,
                'external_fiscal_code' => $data['external_fiscal_code'] ?? null,
                'total_amount' => 0,
                'total_paid' => 0,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $this->syncItems($invoice, $data['items']);
            $this->storeTotals($invoice);

            return $this->find($invoice->fresh());
        });
    }

    public function createAndIssue(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $draft = $this->createDraft($data);

            return $this->issue($draft);
        });
    }

    public function updateDraft(Invoice $invoice, array $data): Invoice
    {
        $this->assertInvoiceCompany($invoice);

        return DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->ensureDraft($invoice);
            $data = $this->applyProfileDefaults($data);
            $data = $this->persistBuyerIfRequested($data);
            $buyer = $this->draftBuyer($data);

            $invoice->update([
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $buyer['legal_name'] ?? null,
                'buyer_snapshot' => $buyer ?: null,
                'invoice_date' => $data['invoice_date'],
                'supply_date' => $data['supply_date'],
                'supply_time' => $data['supply_time'] ?? null,
                'due_at' => $data['due_date'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'notes' => $data['notes'] ?? null,
                'fiscal_receipt_number' => $data['fiscal_receipt_number'] ?? null,
                'external_fiscal_code' => $data['external_fiscal_code'] ?? null,
                'updated_by' => Auth::id(),
            ]);

            $invoice->items()->delete();
            $this->syncItems($invoice, $data['items']);
            $this->storeTotals($invoice);

            return $this->find($invoice->fresh());
        });
    }

    public function deleteDraft(Invoice $invoice): void
    {
        $this->assertInvoiceCompany($invoice);
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->ensureDraft($invoice);
            $invoice->delete();
        });
    }

    public function voidDraft(Invoice $invoice, string $reason): Invoice
    {
        $this->assertInvoiceCompany($invoice);

        return DB::transaction(function () use ($invoice, $reason) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->ensureDraft($invoice);
            $invoice->update([
                'status' => 'void',
                'payment_status' => 'void',
                'compliance_status' => 'void',
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
                'updated_by' => Auth::id(),
            ]);

            return $this->find($invoice->fresh());
        });
    }

    public function issue(Invoice $invoice): Invoice
    {
        $this->assertInvoiceCompany($invoice);

        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->issued_at && in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true)) {
                return $this->find($invoice);
            }
            $this->ensureDraft($invoice);
            if ($invoice->document_type !== 'invoice') {
                throw ValidationException::withMessages(['document_type' => ['Credit notes are issued through the credit-note workflow.']]);
            }

            $profile = InvoiceProfile::query()->first();
            if (! $profile) {
                throw ValidationException::withMessages(['profile' => ['Complete the company invoice profile before issuing an invoice.']]);
            }
            $missing = $this->profiles->missingRequiredFields($profile);
            if ($missing !== []) {
                throw ValidationException::withMessages(['profile' => ['Complete these invoice profile fields: '.implode(', ', $missing).'.']]);
            }

            $buyer = $this->buyerAtIssue($invoice);
            $this->validateBuyer($buyer);

            // Recalculate from saved draft inputs immediately before issue so
            // no client-supplied total can become part of the tax document.
            $draftItems = $invoice->items()->orderBy('id')->get()->map(fn (InvoiceItem $item) => [
                'product_id' => $item->product_id,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'actual_base_quantity' => $item->base_quantity,
                'warehouse_id' => $item->warehouse_id,
                'unit_price' => $item->unit_price,
                'discount_percent' => $item->discount_percent,
                'discount_amount' => $item->discount_amount,
                'vat_rate' => $item->vat_rate,
                'tax_treatment' => $item->tax_treatment,
                'tax_legal_reference' => $item->tax_legal_reference,
            ])->all();
            $invoice->items()->delete();
            $this->syncItems($invoice, $draftItems, true);
            $this->storeTotals($invoice);
            $invoice->refresh()->load('items');
            $this->validateTaxes($invoice, $profile, $buyer);

            $issuedAt = $this->businessNow();
            if ($invoice->due_at && $invoice->due_at->toDateString() < $issuedAt->toDateString()) {
                throw ValidationException::withMessages(['due_date' => ['The due date cannot be earlier than the actual issue date.']]);
            }
            [$sequenceYear, $sequenceNumber, $number] = $this->nextNumber($profile, 'invoice', $issuedAt->year);
            $this->applyStock($invoice, 'out', "Invoice {$number} issued");

            $invoice->update([
                'invoice_number' => $number,
                'sequence_year' => $sequenceYear,
                'sequence_number' => $sequenceNumber,
                'status' => 'issued',
                'payment_status' => 'unpaid',
                'compliance_status' => 'compliant_b2b',
                'seller_snapshot' => $this->profiles->sellerSnapshot($profile),
                'buyer_snapshot' => $buyer,
                'customer_name' => $buyer['legal_name'],
                'invoice_date' => $issuedAt->toDateString(),
                'payment_terms' => $this->issuedPaymentTerms($invoice->payment_terms, $profile),
                'issued_at' => $issuedAt,
                'issued_by' => Auth::id(),
                'stock_applied_at' => now(),
                'retention_until' => CarbonImmutable::create($issuedAt->year + 6, 12, 31)->toDateString(),
                'updated_by' => Auth::id(),
            ]);
            $invoice->update(['integrity_hash' => $this->integrityHash($invoice->fresh('items'))]);

            return $this->find($invoice->fresh());
        });
    }

    public function fullCreditNote(Invoice $original, string $reason): Invoice
    {
        $this->assertInvoiceCompany($original);

        return DB::transaction(function () use ($original, $reason) {
            $original = Invoice::query()->lockForUpdate()->findOrFail($original->id);
            $existing = Invoice::query()
                ->where('original_invoice_id', $original->id)
                ->where('document_type', 'credit_note')
                ->whereNotIn('status', ['void'])
                ->first();
            if ($existing) {
                return $this->find($existing);
            }
            if ($original->document_type !== 'invoice' || $original->status !== 'issued' || Money::compare($original->total_paid, '0.00') > 0) {
                throw ValidationException::withMessages([
                    'invoice' => ['A full credit note is available only for an issued, completely unpaid tax invoice.'],
                ]);
            }
            if (! $original->issued_at || ! $original->seller_snapshot || ! $original->buyer_snapshot) {
                throw ValidationException::withMessages(['invoice' => ['Legacy or incomplete invoices cannot be credited through this workflow.']]);
            }

            $profile = InvoiceProfile::query()->firstOrFail();
            $missing = $this->profiles->missingRequiredFields($profile);
            if ($missing !== []) {
                throw ValidationException::withMessages(['profile' => ['Complete these invoice profile fields: '.implode(', ', $missing).'.']]);
            }
            $issuedAt = $this->businessNow();
            [$year, $sequence, $number] = $this->nextNumber($profile, 'credit_note', $issuedAt->year);
            $credit = Invoice::create([
                'company_id' => $original->company_id,
                'customer_id' => $original->customer_id,
                'invoice_number' => $number,
                'customer_name' => $original->customer_name,
                'status' => 'issued',
                'payment_status' => 'credited',
                'document_type' => 'credit_note',
                'original_invoice_id' => $original->id,
                'credit_reason' => $reason,
                'compliance_status' => 'compliant_b2b',
                'seller_snapshot' => $original->seller_snapshot,
                'buyer_snapshot' => $original->buyer_snapshot,
                'currency' => 'EUR',
                'invoice_date' => $issuedAt->toDateString(),
                'issued_at' => $issuedAt,
                'issued_by' => Auth::id(),
                'supply_date' => $original->supply_date,
                'supply_time' => $original->supply_time,
                'due_at' => $issuedAt->toDateString(),
                'payment_terms' => 'Full credit of '.$original->invoice_number,
                'notes' => $reason,
                'subtotal' => $original->subtotal,
                'discount_total' => $original->discount_total,
                'taxable_total' => $original->taxable_total,
                'vat_total' => $original->vat_total,
                'grand_total' => $original->grand_total,
                'total_amount' => $original->grand_total,
                'total_paid' => 0,
                'sequence_year' => $year,
                'sequence_number' => $sequence,
                'retention_until' => CarbonImmutable::create($issuedAt->year + 6, 12, 31)->toDateString(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $original->load('items');
            foreach ($original->items as $item) {
                $credit->items()->create($item->only([
                    'product_id', 'warehouse_id', 'location_id', 'trace_allocations', 'description', 'sku_snapshot', 'unit', 'conversion_mode', 'conversion_factor', 'quantity', 'base_quantity', 'unit_price',
                    'discount_percent', 'discount_amount', 'taxable_amount', 'vat_rate', 'vat_amount',
                    'line_total', 'tax_treatment', 'tax_legal_reference', 'unit_cost', 'cost_total',
                ]));
            }

            if ($original->stock_applied_at && ! $original->stock_reversed_at) {
                $this->applyStock($original, 'in', "Credit note {$number} for {$original->invoice_number}");
                $original->update(['stock_reversed_at' => now()]);
            }
            $original->update(['status' => 'credited', 'payment_status' => 'credited', 'updated_by' => Auth::id()]);
            $credit->update([
                'stock_reversed_at' => now(),
                'integrity_hash' => $this->integrityHash($credit->fresh('items')),
            ]);

            return $this->find($credit->fresh());
        });
    }

    public function vatSummary(Invoice $invoice): array
    {
        $this->assertInvoiceCompany($invoice);

        return $invoice->items->groupBy(fn (InvoiceItem $item) => $item->tax_treatment.'|'.number_format((float) $item->vat_rate, 2, '.', ''))
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'tax_treatment' => $first->tax_treatment,
                    'vat_rate' => (float) $first->vat_rate,
                    'taxable_amount' => (float) Money::add(...$items->pluck('taxable_amount')->all()),
                    'vat_amount' => (float) Money::add(...$items->pluck('vat_amount')->all()),
                    'legal_reference' => $items->pluck('tax_legal_reference')->filter()->unique()->implode('; '),
                ];
            })->values()->all();
    }

    private function syncItems(Invoice $invoice, array $items, bool $lockProducts = false): void
    {
        $profile = InvoiceProfile::query()->first();
        $isVatRegistered = (bool) ($profile?->is_vat_registered ?? false);

        foreach ($items as $input) {
            $product = null;
            if (! empty($input['product_id'])) {
                $query = Product::query()->with('units');
                if ($lockProducts) {
                    $query->lockForUpdate();
                }
                $product = $query->findOrFail((int) $input['product_id']);
            }

            $description = trim((string) ($input['description'] ?? $product?->name ?? ''));
            if ($description === '') {
                throw ValidationException::withMessages(['items' => ['Every manual invoice line requires a description.']]);
            }
            $quantity = round((float) $input['quantity'], 3);
            try {
                $resolvedUnit = $product
                    ? $this->unitConversions->resolve(
                        $product,
                        $quantity,
                        $input['unit'] ?? $this->unitConversions->defaultUnit($product, 'sale'),
                        isset($input['actual_base_quantity']) ? (float) $input['actual_base_quantity'] : null,
                        'sale',
                    )
                    : ['unit' => trim((string) ($input['unit'] ?? 'pcs')) ?: 'pcs', 'conversion_mode' => 'none', 'conversion_factor' => null, 'base_quantity' => $quantity];
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['items' => collect($exception->errors())->flatten()->all()]);
            }
            $priceValue = $input['unit_price'] ?? $product?->selling_price ?? $product?->price;
            if ($priceValue === null) {
                throw ValidationException::withMessages(['items' => ["Enter a unit price for {$description}."]]);
            }
            $unitPrice = Money::normalize($priceValue);
            $gross = Money::multiply($unitPrice, $quantity);
            $discountPercent = Money::normalizeDecimal($input['discount_percent'] ?? '0', 2);
            $explicitDiscount = Money::normalize($input['discount_amount'] ?? '0');
            $percentageDiscount = Money::compareDecimal($discountPercent, '0') > 0
                ? Money::product([$gross, $discountPercent, '0.01'])
                : '0.00';
            if (Money::compareDecimal($discountPercent, '0') > 0
                && Money::compare($explicitDiscount, '0.00') > 0
                && Money::compare($explicitDiscount, $percentageDiscount) !== 0) {
                throw ValidationException::withMessages(['items' => ['A line discount amount does not match its discount percentage.']]);
            }
            $discount = Money::compareDecimal($discountPercent, '0') > 0 ? $percentageDiscount : $explicitDiscount;
            if (Money::compare($discount, $gross) > 0) {
                throw ValidationException::withMessages(['items' => ["The discount for {$description} cannot exceed its line value."]]);
            }
            $taxable = Money::subtract($gross, $discount);
            // Tax belongs to the legal sales document, not the inventory master.
            // Product selection therefore never silently decides an invoice's VAT.
            $treatment = (string) ($input['tax_treatment'] ?? ($isVatRegistered ? 'standard' : 'non_vat'));
            $vatRate = Money::normalizeDecimal($input['vat_rate'] ?? ($treatment === 'standard' ? '18' : '0'), 2);
            if ($treatment !== 'standard') {
                $vatRate = '0.00';
            }
            $vat = $treatment === 'standard' ? Money::product([$taxable, $vatRate, '0.01']) : '0.00';
            $baseUnitCost = $product ? $this->costing->currentUnitCost($product) : null;
            $costTotal = $baseUnitCost === null ? null : Money::multiply($baseUnitCost, $resolvedUnit['base_quantity']);
            $unitCost = $costTotal === null ? null : Money::divide($costTotal, $quantity, 4);

            $invoice->items()->create([
                'product_id' => $product?->id,
                'warehouse_id' => $product ? ($input['warehouse_id'] ?? $product->default_warehouse_id) : null,
                'location_id' => $product ? ($input['location_id'] ?? null) : null,
                'trace_allocations' => $product ? ($input['trace_allocations'] ?? []) : [],
                'description' => $description,
                'sku_snapshot' => $product?->sku,
                'unit' => $resolvedUnit['unit'],
                'conversion_mode' => $resolvedUnit['conversion_mode'],
                'conversion_factor' => $resolvedUnit['conversion_factor'],
                'quantity' => $quantity,
                'base_quantity' => $resolvedUnit['base_quantity'],
                'unit_price' => $unitPrice,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discount,
                'taxable_amount' => $taxable,
                'vat_rate' => $vatRate,
                'vat_amount' => $vat,
                'line_total' => Money::add($taxable, $vat),
                'tax_treatment' => $treatment,
                'tax_legal_reference' => $input['tax_legal_reference'] ?? null,
                'unit_cost' => $unitCost,
                'cost_total' => $costTotal,
            ]);
        }
    }

    private function storeTotals(Invoice $invoice): void
    {
        $items = $invoice->items()->get();
        $subtotal = Money::add(...$items->map(fn ($item) => Money::add($item->taxable_amount, $item->discount_amount))->all());
        $discount = Money::add(...$items->pluck('discount_amount')->all());
        $taxable = Money::add(...$items->pluck('taxable_amount')->all());
        $vat = Money::add(...$items->pluck('vat_amount')->all());
        $grand = Money::add($taxable, $vat);
        if (collect([$subtotal, $discount, $taxable, $vat, $grand])
            ->contains(fn (string $amount): bool => Money::compare($amount, '9000000000000.00') > 0)) {
            throw ValidationException::withMessages(['items' => ['Invoice totals exceed the supported accounting limit.']]);
        }
        $invoice->update([
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'taxable_total' => $taxable,
            'vat_total' => $vat,
            'grand_total' => $grand,
            'total_amount' => $grand,
        ]);
    }

    private function validateTaxes(Invoice $invoice, InvoiceProfile $profile, array $buyer): void
    {
        foreach ($invoice->items as $item) {
            $treatment = $item->tax_treatment;
            $rate = (float) $item->vat_rate;
            if ($treatment === 'standard' && ! in_array($rate, [8.0, 18.0], true)) {
                throw ValidationException::withMessages(['items' => ["{$item->description} must use Kosovo's 18% or 8% VAT rate, or a supported zero-tax treatment."]]);
            }
            if (in_array($treatment, ['zero_rated', 'exempt', 'reverse_charge'], true) && blank($item->tax_legal_reference)) {
                throw ValidationException::withMessages(['items' => ["Enter the legal VAT basis for {$item->description}."]]);
            }
            if ($profile->is_vat_registered && $treatment === 'non_vat' && blank($item->tax_legal_reference)) {
                throw ValidationException::withMessages(['items' => ["Enter the legal basis for treating {$item->description} as outside VAT."]]);
            }
            if ($treatment === 'reverse_charge' && (! ($buyer['is_vat_registered'] ?? false) || blank($buyer['vat_number'] ?? null))) {
                throw ValidationException::withMessages(['buyer' => ['A reverse-charge invoice requires a VAT-registered buyer with a VAT number.']]);
            }
            if (! $profile->is_vat_registered && ($treatment !== 'non_vat' || (float) $item->vat_amount > 0 || $rate > 0)) {
                throw ValidationException::withMessages(['items' => ['A company that is not VAT registered cannot charge VAT. Use the non-VAT treatment and a 0% rate.']]);
            }
        }
    }

    private function applyStock(Invoice $invoice, string $type, string $reason): void
    {
        $invoice->loadMissing('items');
        $quantities = $invoice->items->whereNotNull('product_id')
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
            ])->sortKeys();
        foreach ($quantities as $group) {
            $stockKey = "invoice-stock-{$invoice->id}-out-{$group['product_id']}-".($group['warehouse_id'] ?? 'auto').'-'.($group['location_id'] ?? 'unassigned');
            $movement = [
                'product_id' => $group['product_id'],
                'warehouse_id' => $group['warehouse_id'],
                'location_id' => $group['location_id'],
                'type' => $type,
                'quantity' => $group['quantity'],
                'reason' => $reason,
                'movement_code' => $type === 'out' ? 'invoice_sale' : 'invoice_credit',
                'source_type' => 'invoice',
                'source_id' => $invoice->id,
                'idempotency_key' => $type === 'out' ? $stockKey : null,
                'trace_allocations' => $group['trace_allocations'],
                'metadata' => $type === 'out' ? [
                    'inventory_group_key' => $group['product_id'].'|'.($group['warehouse_id'] ?? 0).'|'.($group['location_id'] ?? 0),
                ] : null,
            ];
            if ($type === 'out') {
                $this->stockMovements->storeOutboundAllocated($movement);
                continue;
            }

            $outbound = StockMovement::withoutGlobalScopes()->with('traceLines')
                ->where('source_type', 'invoice')->where('source_id', $invoice->id)
                ->where('movement_code', 'invoice_sale')->where('product_id', $group['product_id'])
                ->where('warehouse_id', $group['warehouse_id'])
                ->where(function ($query) use ($stockKey): void {
                    $query->where('idempotency_key', $stockKey)
                        ->orWhere('idempotency_key', 'like', $stockKey.'-part-%');
                })->orderBy('id')->get();
            if ($outbound->isEmpty()) {
                $outbound = StockMovement::withoutGlobalScopes()->with('traceLines')
                    ->where('source_type', 'invoice')->where('source_id', $invoice->id)
                    ->where('movement_code', 'invoice_sale')->where('product_id', $group['product_id'])
                    ->where('warehouse_id', $group['warehouse_id'])->where('location_id', $group['location_id'])
                    ->orderBy('id')->get();
            }
            if ($outbound->isEmpty() || abs(round((float) $outbound->sum('quantity'), 3) - (float) $group['quantity']) >= 0.0005) {
                throw ValidationException::withMessages(['items' => ['The issued invoice no longer matches its inventory movements; reconcile it before crediting.']]);
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
                    'idempotency_key' => "invoice-stock-{$invoice->id}-in-{$original->id}",
                    'metadata' => ['reverses_stock_movement_id' => $original->id],
                ]);
            }
        }
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

    private function nextNumber(InvoiceProfile $profile, string $documentType, int $year): array
    {
        $companyId = (int) Auth::user()->company_id;
        $prefix = strtoupper($documentType === 'credit_note' ? $profile->credit_note_prefix : $profile->invoice_prefix);
        $sequence = InvoiceSequence::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('document_type', $documentType)->where('year', $year)
            ->lockForUpdate()->first();
        if (! $sequence) {
            $initialNumber = $this->nextNumberAfterExistingDocuments($companyId, $prefix, $year);
            try {
                InvoiceSequence::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'document_type' => $documentType,
                    'year' => $year,
                    'next_number' => $initialNumber,
                ]);
            } catch (QueryException) {
                // A concurrent request created the row; the unique constraint
                // makes re-reading it safe.
            }
            $sequence = InvoiceSequence::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('document_type', $documentType)->where('year', $year)
                ->lockForUpdate()->firstOrFail();
        }
        $number = max(
            (int) $sequence->next_number,
            $this->nextNumberAfterExistingDocuments($companyId, $prefix, $year),
        );
        while (Invoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('invoice_number', sprintf('%s-%d-%06d', $prefix, $year, $number))
            ->exists()) {
            $number++;
        }
        $sequence->update(['next_number' => $number + 1]);

        return [$year, $number, sprintf('%s-%d-%06d', $prefix, $year, $number)];
    }

    private function nextNumberAfterExistingDocuments(int $companyId, string $prefix, int $year): int
    {
        $pattern = '/^'.preg_quote($prefix, '/').'-'.preg_quote((string) $year, '/').'-(\d+)$/i';
        $maximum = Invoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('invoice_number', 'like', $prefix.'-'.$year.'-%')
            ->pluck('invoice_number')
            ->reduce(function (int $maximum, ?string $number) use ($pattern): int {
                if (! $number || preg_match($pattern, $number, $matches) !== 1) {
                    return $maximum;
                }

                return max($maximum, (int) $matches[1]);
            }, 0);

        return $maximum + 1;
    }

    private function draftBuyer(array $data): array
    {
        $customer = ! empty($data['customer_id']) ? Customer::query()->findOrFail($data['customer_id']) : null;

        return array_filter(array_merge($customer ? $this->customerSnapshot($customer) : [], $data['buyer'] ?? []), fn ($value) => $value !== null && $value !== '');
    }

    private function persistBuyerIfRequested(array $data): array
    {
        if (! ($data['save_customer'] ?? false) || empty($data['buyer'])) {
            return $data;
        }
        if (! app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'customers.manage')) {
            abort(403, 'You do not have permission to create or update customer records from an invoice.');
        }

        $buyer = $data['buyer'];
        $legalName = trim((string) ($buyer['legal_name'] ?? ''));
        if ($legalName === '') {
            throw ValidationException::withMessages(['buyer.legal_name' => ['A legal name is required to save this buyer.']]);
        }

        $customer = ! empty($data['customer_id'])
            ? Customer::query()->findOrFail($data['customer_id'])
            : null;
        if (! $customer && ! empty($buyer['business_registration_number'])) {
            $customer = Customer::query()
                ->where('business_registration_number', $buyer['business_registration_number'])
                ->first();
        }
        if (! $customer && ! empty($buyer['fiscal_number'])) {
            $customer = Customer::query()->where('fiscal_number', $buyer['fiscal_number'])->first();
        }

        $values = [
            'name' => trim((string) ($buyer['trade_name'] ?? '')) ?: $legalName,
            'business_name' => $legalName,
            'customer_type' => $buyer['customer_type'] ?? 'business',
            'business_registration_number' => $buyer['business_registration_number'] ?? null,
            'fiscal_number' => $buyer['fiscal_number'] ?? null,
            'tax_number' => $buyer['fiscal_number'] ?? $buyer['business_registration_number'] ?? null,
            'is_vat_registered' => (bool) ($buyer['is_vat_registered'] ?? false),
            'vat_number' => ($buyer['is_vat_registered'] ?? false) ? ($buyer['vat_number'] ?? null) : null,
            'address' => $buyer['address'] ?? null,
            'billing_address' => $buyer['address'] ?? null,
            'municipality' => $buyer['municipality'] ?? null,
            'postal_code' => $buyer['postal_code'] ?? null,
            'country_code' => strtoupper((string) ($buyer['country_code'] ?? 'XK')),
            'phone' => $buyer['phone'] ?? null,
            'email' => $buyer['email'] ?? null,
            'is_active' => true,
        ];

        if ($customer) {
            $customer->update($values);
        } else {
            $customer = Customer::create($values);
        }
        $data['customer_id'] = $customer->id;

        return $data;
    }

    private function applyProfileDefaults(array $data): array
    {
        $profile = InvoiceProfile::query()->first();
        if (! $profile) {
            return $data;
        }
        if (empty($data['due_date'])) {
            $data['due_date'] = CarbonImmutable::parse($data['invoice_date'])
                ->addDays((int) $profile->default_payment_terms_days)
                ->toDateString();
        }
        if (blank($data['payment_terms'] ?? null)) {
            $data['payment_terms'] = $profile->default_payment_terms
                ?: $profile->default_payment_terms_days.' days / ditë';
        }

        return $data;
    }

    private function issuedPaymentTerms(?string $draftTerms, InvoiceProfile $profile): string
    {
        $terms = trim((string) $draftTerms);
        if ($terms !== '' && ! preg_match('/^\d+$/', $terms)) {
            return $terms;
        }

        $days = $terms === ''
            ? (int) $profile->default_payment_terms_days
            : (int) $terms;
        if ($days === (int) $profile->default_payment_terms_days && filled($profile->default_payment_terms)) {
            return trim((string) $profile->default_payment_terms);
        }

        return "Payment due within {$days} days / Pagesa duhet t\u{00EB} kryhet brenda {$days} dit\u{00EB}ve";
    }

    private function buyerAtIssue(Invoice $invoice): array
    {
        $customer = $invoice->customer_id ? Customer::query()->findOrFail($invoice->customer_id) : null;

        return array_merge($customer ? $this->customerSnapshot($customer) : [], $invoice->buyer_snapshot ?? []);
    }

    private function customerSnapshot(Customer $customer): array
    {
        return [
            'customer_id' => $customer->id,
            'customer_type' => $customer->customer_type ?? 'business',
            'legal_name' => $customer->business_name ?: $customer->name,
            'trade_name' => $customer->business_name ? $customer->name : null,
            'business_registration_number' => $customer->business_registration_number,
            'fiscal_number' => $customer->fiscal_number ?: $customer->tax_number,
            'is_vat_registered' => (bool) $customer->is_vat_registered,
            'vat_number' => $customer->vat_number,
            'address' => $customer->billing_address ?: $customer->address,
            'municipality' => $customer->municipality,
            'postal_code' => $customer->postal_code,
            'country_code' => $customer->country_code ?: 'XK',
            'phone' => $customer->phone,
            'email' => $customer->email,
        ];
    }

    private function validateBuyer(array $buyer): void
    {
        $missing = [];
        foreach (['legal_name', 'address'] as $field) {
            if (blank($buyer[$field] ?? null)) {
                $missing[] = $field;
            }
        }
        if (blank($buyer['business_registration_number'] ?? null) && blank($buyer['fiscal_number'] ?? null)) {
            $missing[] = 'business_registration_number or fiscal_number';
        }
        if (($buyer['is_vat_registered'] ?? false) && blank($buyer['vat_number'] ?? null)) {
            $missing[] = 'vat_number';
        }
        if ($missing !== []) {
            throw ValidationException::withMessages(['buyer' => ['Complete these buyer invoice fields: '.implode(', ', $missing).'.']]);
        }
    }

    private function integrityHash(Invoice $invoice): string
    {
        $payload = [
            'invoice_number' => $invoice->invoice_number,
            'document_type' => $invoice->document_type,
            'original_invoice_id' => $invoice->original_invoice_id,
            'credit_reason' => $invoice->credit_reason,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'supply_date' => $invoice->supply_date?->toDateString(),
            'supply_time' => $invoice->supply_time,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toDateString(),
            'payment_terms' => $invoice->payment_terms,
            'notes' => $invoice->notes,
            'seller' => $invoice->seller_snapshot,
            'buyer' => $invoice->buyer_snapshot,
            'currency' => 'EUR',
            'sequence' => [$invoice->sequence_year, $invoice->sequence_number],
            'issued_by' => $invoice->issued_by,
            'fiscal_receipt_number' => $invoice->fiscal_receipt_number,
            'external_fiscal_code' => $invoice->external_fiscal_code,
            'retention_until' => $invoice->retention_until?->toDateString(),
            'totals' => [$invoice->subtotal, $invoice->discount_total, $invoice->taxable_total, $invoice->vat_total, $invoice->grand_total],
            'items' => $invoice->items->map(fn ($item) => $item->only([
                'description', 'sku_snapshot', 'unit', 'conversion_mode', 'conversion_factor', 'quantity', 'base_quantity', 'warehouse_id', 'unit_price', 'discount_amount',
                'taxable_amount', 'vat_rate', 'vat_amount', 'line_total', 'tax_treatment', 'tax_legal_reference',
            ]))->values()->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function verifyIntegrity(Invoice $invoice): void
    {
        if (! $invoice->issued_at || $invoice->compliance_status !== 'compliant_b2b' || blank($invoice->integrity_hash)) {
            return;
        }
        $invoice->loadMissing('items');
        if (! hash_equals((string) $invoice->integrity_hash, $this->integrityHash($invoice))) {
            throw ValidationException::withMessages([
                'integrity' => ['The issued invoice no longer matches its stored AIMS internal document checksum.'],
            ]);
        }
    }

    private function ensureDraft(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft' || $invoice->issued_at) {
            throw ValidationException::withMessages(['status' => ['Only draft invoices can be changed, voided or deleted.']]);
        }
    }

    private function businessNow(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/Belgrade');
    }

    private function requireCompanyId(): int
    {
        $companyId = Auth::user()?->company_id;
        abort_unless($companyId, 403, 'Select an explicit company context before using company invoice data.');

        return (int) $companyId;
    }

    private function assertInvoiceCompany(Invoice $invoice): void
    {
        abort_unless((int) $invoice->company_id === $this->requireCompanyId(), 404);
    }
}
