<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Money;

class Invoice extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'invoice_number',
        'document_type',
        'original_invoice_id',
        'credit_reason',
        'customer_id',
        'daily_sale_id',
        'customer_name',
        'seller_snapshot',
        'buyer_snapshot',
        'status',
        'compliance_status',
        'payment_status',
        'currency',
        'invoice_date',
        'supply_date',
        'supply_time',
        'payment_terms',
        'payment_method',
        'subtotal',
        'discount_total',
        'taxable_total',
        'vat_total',
        'grand_total',
        'total_amount',
        'total_paid',
        'sequence_year',
        'sequence_number',
        'issued_at',
        'issued_by',
        'due_at',
        'paid_at',
        'voided_at',
        'voided_by',
        'void_reason',
        'stock_applied_at',
        'stock_reversed_at',
        'integrity_hash',
        'fiscal_receipt_number',
        'external_fiscal_code',
        'retention_until',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'customer_id' => 'integer',
            'daily_sale_id' => 'integer',
            'original_invoice_id' => 'integer',
            'issued_by' => 'integer',
            'voided_by' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'seller_snapshot' => 'array',
            'buyer_snapshot' => 'array',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'sequence_year' => 'integer',
            'sequence_number' => 'integer',
            'invoice_date' => 'date',
            'supply_date' => 'date',
            'issued_at' => 'datetime',
            'due_at' => 'date',
            'paid_at' => 'date',
            'voided_at' => 'datetime',
            'stock_applied_at' => 'datetime',
            'stock_reversed_at' => 'datetime',
            'retention_until' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function dailySale(): BelongsTo
    {
        return $this->belongsTo(DailySale::class);
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function debtTransactions(): HasMany
    {
        return $this->hasMany(CustomerDebtTransaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by')->withTrashed();
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by')->withTrashed();
    }

    public function getRemainingBalanceAttribute(): float
    {
        if ($this->document_type === 'credit_note' || in_array($this->status, ['credited', 'void'], true)) {
            return 0.0;
        }
        // Invoice relations are sometimes intentionally loaded with only a
        // small column subset. Treat omitted monetary columns as zero rather
        // than attempting to parse null while serializing the relation.
        $totalPaid = $this->getAttributeFromArray('total_paid') ?? '0.00';

        return (float) Money::subtract($this->resolvedTotal(), $totalPaid);
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->remaining_balance <= 0;
    }

    public function getDueDateAttribute(): ?string
    {
        return $this->due_at?->toDateString();
    }

    public function getSignedTotalAttribute(): float
    {
        $total = $this->resolvedTotal();

        return (float) ($this->document_type === 'credit_note' ? Money::subtract('0.00', $total) : $total);
    }

    private function resolvedTotal(): string
    {
        $grandTotal = $this->getAttributeFromArray('grand_total') ?? '0.00';
        $legacyTotal = $this->getAttributeFromArray('total_amount') ?? '0.00';

        return Money::compare($grandTotal, $legacyTotal) >= 0
            ? Money::normalize($grandTotal)
            : Money::normalize($legacyTotal);
    }

    protected $appends = ['remaining_balance', 'is_paid', 'due_date', 'signed_total'];
}
