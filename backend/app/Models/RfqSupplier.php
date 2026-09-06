<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RfqSupplier extends Model { protected $fillable=['rfq_id','supplier_id','sent_at','status']; protected function casts():array{return ['sent_at'=>'datetime'];} }
