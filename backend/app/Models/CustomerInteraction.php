<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class CustomerInteraction extends Model { use BelongsToCompany; protected $guarded=['id']; protected function casts(): array {return ['occurred_at'=>'datetime'];} }
