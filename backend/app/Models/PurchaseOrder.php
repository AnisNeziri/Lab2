<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'supplier_id', 'warehouse_id', 'po_number', 'status', 'total_amount', 'total_amount_eur', 'total_paid',
        'currency', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source', 'ordered_at', 'expected_at', 'received_at', 'cancelled_at', 'cancelled_by',
        'due_at', 'notes', 'created_by', 'updated_by',
    ];

    protected $appends = ['remaining_balance', 'payment_status', 'is_overdue'];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'total_amount_eur' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'exchange_rate_date' => 'date:Y-m-d',
            'ordered_at' => 'date:Y-m-d',
            'expected_at' => 'date:Y-m-d',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'due_at' => 'date:Y-m-d',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseOrderPayment::class);
    }

    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(Expense::class)->where('document_type', 'purchase_invoice')->where('status', '!=', 'reversed');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(PurchaseOrderChange::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->latest('received_at');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->latest();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getRemainingBalanceAttribute(): float
    {
        return max(0, round((float) $this->total_amount - (float) $this->total_paid, 2));
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->remaining_balance > 0
            && $this->due_at
            && $this->due_at->toDateString() < now('Europe/Tirane')->toDateString()
            && $this->status !== 'cancelled';
    }

    public function getPaymentStatusAttribute(): string
    {
        if ($this->is_overdue) {
            return 'overdue';
        }
        if ($this->remaining_balance <= 0) {
            return 'paid';
        }
        if ((float) $this->total_paid > 0) {
            return 'partially_paid';
        }

        return 'unpaid';
    }
}
