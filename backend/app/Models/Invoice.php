<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'invoice_number',
        'customer_name',
        'status',
        'total_amount',
        'total_paid',
        'issued_at',
        'due_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'total_paid'   => 'decimal:2',
            'issued_at'    => 'date',
            'due_at'       => 'date',
            'paid_at'      => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function getRemainingBalanceAttribute(): float
    {
        return round((float) $this->total_amount - (float) $this->total_paid, 2);
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->remaining_balance <= 0;
    }
}
