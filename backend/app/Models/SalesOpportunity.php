<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class SalesOpportunity extends Model { use BelongsToCompany; protected $guarded=['id']; public function customer(){return $this->belongsTo(Customer::class);} protected function casts(): array {return ['expected_value'=>'decimal:2','expected_close_on'=>'date:Y-m-d','next_action_on'=>'date:Y-m-d'];} }
