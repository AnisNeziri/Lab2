<?php
namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class OutboundDispatch extends Model
{
    use BelongsToCompany;
    protected $table = 'outbound_dispatches';
    protected $guarded = ['id'];
    protected function casts(): array { return ['metadata'=>'array','confirmed_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime','dispatched_at'=>'datetime','delivered_at'=>'datetime','requested_delivery_date'=>'date:Y-m-d','order_date'=>'date:Y-m-d']; }
    public function packages(){return $this->hasMany(OutboundPackage::class);} public function order(){return $this->belongsTo(SalesOrder::class,'sales_order_id');}
}

