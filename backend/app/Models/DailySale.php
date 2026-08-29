<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailySale extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'sale_number',
        'sale_date',
        'customer_name',
        'customer_id',
        'status',
        'notes',
        'signature_name',
        'total_amount',
        'paid_amount',
        'payment_method',
        'total_quantity',
        'created_by',
        'updated_by',
        'finalized_at',
        'inventory_applied_at',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'total_quantity' => 'decimal:3',
            'finalized_at' => 'datetime',
            'inventory_applied_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DailySaleItem::class)->orderBy('line_number');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeFinalized($query)
    {
        return $query->where('status', 'finalized');
    }
}
