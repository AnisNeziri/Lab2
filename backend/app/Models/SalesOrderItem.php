<?php
namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SalesOrderItem extends Model
{
    use BelongsToCompany;
    protected $table = 'sales_order_items';
    protected $guarded = ['id'];
    protected function casts(): array { return ['metadata'=>'array','confirmed_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime','dispatched_at'=>'datetime','delivered_at'=>'datetime','requested_delivery_date'=>'date:Y-m-d','order_date'=>'date:Y-m-d']; }
    public function order(){return $this->belongsTo(SalesOrder::class,'sales_order_id');} public function product(){return $this->belongsTo(Product::class);} public function allocations(){return $this->hasMany(OutboundAllocation::class);}
}

