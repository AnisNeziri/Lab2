<?php
namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class OutboundAllocation extends Model
{
    use BelongsToCompany;
    protected $table = 'outbound_allocations';
    protected $guarded = ['id'];
    protected function casts(): array { return ['metadata'=>'array','confirmed_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime','dispatched_at'=>'datetime','delivered_at'=>'datetime','requested_delivery_date'=>'date:Y-m-d','order_date'=>'date:Y-m-d']; }
    public function item(){return $this->belongsTo(SalesOrderItem::class,'sales_order_item_id');} public function warehouse(){return $this->belongsTo(Warehouse::class);} public function location(){return $this->belongsTo(WarehouseLocation::class);} public function lot(){return $this->belongsTo(InventoryLot::class,'inventory_lot_id');}
}

