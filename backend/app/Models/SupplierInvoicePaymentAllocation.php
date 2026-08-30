<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoicePaymentAllocation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'purchase_order_payment_id', 'expense_id', 'amount',
        'allocated_by', 'allocated_at', 'reason',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'allocated_at' => 'datetime'];
    }

    public function payment(): BelongsTo { return $this->belongsTo(PurchaseOrderPayment::class, 'purchase_order_payment_id'); }
    public function expense(): BelongsTo { return $this->belongsTo(Expense::class); }
    public function allocator(): BelongsTo { return $this->belongsTo(User::class, 'allocated_by')->withTrashed(); }
}
