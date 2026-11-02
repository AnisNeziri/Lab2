<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class CustomerLocation extends Model { use BelongsToCompany; protected $guarded=['id']; protected function casts(): array {return ['is_active'=>'boolean'];} }
