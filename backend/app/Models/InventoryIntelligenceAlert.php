<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class InventoryIntelligenceAlert extends Model {
    use BelongsToCompany;
    protected $guarded=['id'];
    protected function casts():array{return ['evidence'=>'array','opened_at'=>'datetime','resolved_at'=>'datetime'];}
    public function product(){return $this->belongsTo(Product::class);}
}
