<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\Money;

class Expense extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'document_version_id', 'company_id', 'vendor_name', 'vendor_business_number', 'vendor_fiscal_number',
        'vendor_vat_number', 'vendor_key', 'document_type', 'document_number',
        'document_number_normalized', 'original_document_number', 'source_type',
        'asset_treatment', 'category', 'description', 'business_purpose', 'invoice_date', 'received_date', 'supply_date',
        'due_date', 'currency', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source', 'net_amount', 'vat_rate', 'vat_amount', 'self_assessed_vat_amount',
        'gross_amount', 'vat_treatment', 'input_vat_eligible', 'deductible_vat_amount',
        'non_deductible_vat_amount', 'net_amount_eur', 'vat_amount_eur', 'self_assessed_vat_amount_eur', 'gross_amount_eur',
        'deductible_vat_amount_eur', 'non_deductible_vat_amount_eur', 'tax_legal_reference',
        'notes', 'status', 'vat_period', 'posted_at', 'posted_by', 'reversed_at',
        'reversed_by', 'reversal_reason', 'retention_until', 'proof_filename', 'proof_mime',
        'proof_size', 'proof_sha256', 'proof_data', 'created_by', 'updated_by',
        'supplier_id', 'purchase_order_id', 'original_expense_id', 'match_status', 'match_summary',
        'approved_at', 'approved_by',
    ];

    protected $hidden = ['proof_data', 'vendor_key', 'document_number_normalized'];

    protected $appends = ['paid_amount', 'remaining_amount', 'payment_status', 'has_attachment'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'input_vat_eligible' => 'boolean',
            'exchange_rate' => 'decimal:6', 'net_amount' => 'decimal:2', 'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2', 'self_assessed_vat_amount' => 'decimal:2', 'gross_amount' => 'decimal:2',
            'deductible_vat_amount' => 'decimal:2', 'non_deductible_vat_amount' => 'decimal:2',
            'net_amount_eur' => 'decimal:2', 'vat_amount_eur' => 'decimal:2', 'self_assessed_vat_amount_eur' => 'decimal:2',
            'gross_amount_eur' => 'decimal:2', 'deductible_vat_amount_eur' => 'decimal:2',
            'non_deductible_vat_amount_eur' => 'decimal:2', 'invoice_date' => 'date',
            'received_date' => 'date', 'supply_date' => 'date', 'due_date' => 'date', 'exchange_rate_date' => 'date', 'posted_at' => 'datetime',
            'reversed_at' => 'datetime', 'retention_until' => 'date', 'proof_size' => 'integer',
            'match_summary' => 'array', 'approved_at' => 'datetime',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class)->withTrashed();
    }

    public function supplierInvoiceItems(): HasMany
    {
        return $this->hasMany(SupplierInvoiceItem::class);
    }

    public function goodsReceipts()
    {
        return $this->belongsToMany(GoodsReceipt::class, 'expense_goods_receipts');
    }

    public function matchEvents(): HasMany
    {
        return $this->hasMany(SupplierMatchEvent::class);
    }

    public function purchaseOrderPayments(): HasMany
    {
        return $this->hasMany(PurchaseOrderPayment::class);
    }

    public function purchaseOrderPaymentAllocations(): HasMany
    {
        return $this->hasMany(SupplierInvoicePaymentAllocation::class);
    }

    public function supplierCredits(): HasMany
    {
        return $this->hasMany(self::class, 'original_expense_id')
            ->where('document_type', 'credit_note')
            ->where('status', 'posted');
    }

    public function getPaidAmountAttribute(): float
    {
        $direct = ($this->attributes['payments_sum_amount']
            ?? $this->payments->where('status', 'completed')->sum('amount'));
        $advances = ($this->attributes['purchase_order_payment_allocations_sum_amount']
            ?? ($this->relationLoaded('purchaseOrderPaymentAllocations') ? $this->purchaseOrderPaymentAllocations->sum('amount') : $this->purchaseOrderPaymentAllocations()->sum('amount')));
        $credits = ($this->attributes['supplier_credits_sum_gross_amount']
            ?? ($this->relationLoaded('supplierCredits') ? $this->supplierCredits->sum('gross_amount') : $this->supplierCredits()->sum('gross_amount')));

        return (float) Money::add($direct, $advances, $credits);
    }

    public function getRemainingAmountAttribute(): float
    {
        if ($this->document_type === 'credit_note' || $this->status === 'reversed') {
            return 0.0;
        }

        return (float) Money::maximum('0.00', Money::subtract($this->gross_amount, $this->paid_amount));
    }

    public function getPaymentStatusAttribute(): string
    {
        if ($this->document_type === 'credit_note') {
            return 'not_applicable';
        }

        if ($this->paid_amount <= 0) {
            return 'unpaid';
        }

        return $this->remaining_amount <= 0 ? 'paid' : 'partially_paid';
    }

    public function getHasAttachmentAttribute(): bool
    {
        return filled($this->proof_filename);
    }
}
