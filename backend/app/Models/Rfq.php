<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Rfq extends Model { use BelongsToCompany; protected $fillable=['company_id','purchase_request_id','rfq_number','status','issued_at','response_due_at','notes','created_by']; protected function casts():array{return ['issued_at'=>'date:Y-m-d','response_due_at'=>'date:Y-m-d'];} public function purchaseRequest():BelongsTo{return $this->belongsTo(PurchaseRequest::class);} public function suppliers():HasMany{return $this->hasMany(RfqSupplier::class);} public function quotes():HasMany{return $this->hasMany(SupplierQuote::class);} }
