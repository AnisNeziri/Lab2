<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryExpiryAlertState extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'inventory_lot_id', 'notification_id', 'alert_state',
        'notified_state', 'active_quantity', 'last_seen_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'active_quantity' => 'decimal:3',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
