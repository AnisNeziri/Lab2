<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'inventory_count_item_id', 'count_round', 'entry_type',
        'counted_quantity', 'notes', 'entered_by', 'entered_at',
    ];

    protected function casts(): array
    {
        return ['counted_quantity' => 'decimal:3', 'count_round' => 'integer', 'entered_at' => 'datetime'];
    }

    public function item(): BelongsTo { return $this->belongsTo(InventoryCountItem::class, 'inventory_count_item_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'entered_by'); }
}
