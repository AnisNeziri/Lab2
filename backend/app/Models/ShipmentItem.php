<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'shipment_id', 'shipment_container_id', 'purchase_order_item_id',
        'product_id', 'description', 'unit', 'quantity', 'base_quantity',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'base_quantity' => 'decimal:3'];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function container(): BelongsTo { return $this->belongsTo(ShipmentContainer::class, 'shipment_container_id'); }
    public function purchaseOrderItem(): BelongsTo { return $this->belongsTo(PurchaseOrderItem::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
