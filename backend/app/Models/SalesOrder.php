<?php
namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    use BelongsToCompany;
    protected $table = 'sales_orders';
    protected $guarded = ['id'];
    public function intake(){return $this->hasOne(OrderIntake::class);}
    protected function casts(): array { return ['metadata'=>'array','confirmed_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime','dispatched_at'=>'datetime','delivered_at'=>'datetime','requested_delivery_date'=>'date:Y-m-d','order_date'=>'date:Y-m-d']; }
    public function customer(){return $this->belongsTo(Customer::class);} public function items(){return $this->hasMany(SalesOrderItem::class);} public function allocations(){return $this->hasMany(OutboundAllocation::class);} public function tasks(){return $this->hasMany(PickTask::class);} public function packages(){return $this->hasMany(OutboundPackage::class);} public function dispatches(){return $this->hasMany(OutboundDispatch::class);} public function returns(){return $this->hasMany(OutboundReturn::class);}
}
