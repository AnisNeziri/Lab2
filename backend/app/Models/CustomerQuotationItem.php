<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class CustomerQuotationItem extends Model {
 use BelongsToCompany; public $timestamps=false; protected $guarded=['id'];
 protected function casts(): array {return ['quantity'=>'decimal:3','unit_price'=>'decimal:2','line_total'=>'decimal:2','pricing_snapshot'=>'array'];}
}
