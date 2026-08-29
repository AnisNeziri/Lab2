<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLot extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'product_id', 'tracking_mode_snapshot', 'identity_key',
        'lot_number', 'serial_number', 'supplier_batch', 'manufactured_at',
        'expiry_at', 'status', 'created_by',
    ];

    protected $appends = ['quantity_remaining', 'expiry_status'];

    protected function casts(): array
    {
        return [
            'manufactured_at' => 'date',
            'expiry_at' => 'date',
            'created_by' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryTraceBalance::class);
    }

    public function movementLines(): HasMany
    {
        return $this->hasMany(StockMovementTrace::class);
    }

    protected function quantityRemaining(): Attribute
    {
        return Attribute::get(fn () => round((float) (
            array_key_exists('balances_sum_quantity', $this->attributes)
                ? $this->attributes['balances_sum_quantity']
                : ($this->relationLoaded('balances') ? $this->balances->sum('quantity') : $this->balances()->sum('quantity'))
        ), 3));
    }

    protected function expiryStatus(): Attribute
    {
        return Attribute::get(function (): string {
            if (! $this->expiry_at) {
                return 'not_applicable';
            }
            $today = now()->startOfDay();
            if ($this->expiry_at->lt($today)) {
                return 'expired';
            }
            $warningDays = (int) ($this->product?->near_expiry_days ?? 30);

            return $this->expiry_at->lte($today->copy()->addDays($warningDays)) ? 'near_expiry' : 'ok';
        });
    }
}
