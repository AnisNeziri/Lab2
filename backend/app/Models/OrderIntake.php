<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToCompany;
class OrderIntake extends Model {
    use BelongsToCompany;
    protected $guarded=['id'];
    protected $hidden=['tracking_hash'];
    protected function casts():array{return ['payload'=>'array','received_payload'=>'array','last_conflict'=>'array','issues'=>'array','resolution'=>'array','tracking_expires_at'=>'datetime','last_success_at'=>'datetime','next_retry_at'=>'datetime'];}
    public function channel(){return $this->belongsTo(OrderChannel::class,'order_channel_id');}
    public function order(){return $this->belongsTo(SalesOrder::class,'sales_order_id');}
}
