<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToCompany;
class OrderChannel extends Model {
    use BelongsToCompany;
    protected $guarded=['id'];
    protected function casts():array{return ['enabled'=>'boolean','allow_guest'=>'boolean','configuration'=>'array','price_tolerance'=>'decimal:2'];}
    public function intakes(){return $this->hasMany(OrderIntake::class);}
}
