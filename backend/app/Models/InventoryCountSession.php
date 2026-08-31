<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCountSession extends Model
{
    use BelongsToCompany;

    /**
     * `frozen_at` is the legacy database column name. Counts do not lock or
     * freeze live stock; expose the value with snapshot terminology to API
     * consumers while retaining the old field for backwards compatibility.
     */
    protected $appends = ['snapshot_at'];

    protected $fillable = [
        'company_id', 'count_number', 'warehouse_id', 'location_id', 'status',
        'stock_states', 'scope_all_products', 'scope_product_ids', 'snapshot_identity_keys',
        'frozen_at', 'submitted_at', 'approved_at', 'cancelled_at',
        'created_by', 'submitted_by', 'approved_by', 'cancelled_by', 'notes',
        'approval_reason', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'stock_states' => 'array', 'scope_all_products' => 'boolean',
            'scope_product_ids' => 'array', 'snapshot_identity_keys' => 'array',
            'frozen_at' => 'datetime', 'submitted_at' => 'datetime',
            'approved_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function getSnapshotAtAttribute(): mixed
    {
        return $this->frozen_at;
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function location(): BelongsTo { return $this->belongsTo(WarehouseLocation::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by')->withTrashed(); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by')->withTrashed(); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by')->withTrashed(); }
    public function items(): HasMany { return $this->hasMany(InventoryCountItem::class); }
}
