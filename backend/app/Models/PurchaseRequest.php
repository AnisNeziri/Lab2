<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
class PurchaseRequest extends Model { use BelongsToCompany, SoftDeletes; protected $fillable=['company_id','request_number','status','requested_by','requested_at','required_by','notes','estimated_total','currency','approval_request_id']; protected function casts(): array { return ['requested_at'=>'date:Y-m-d','required_by'=>'date:Y-m-d','estimated_total'=>'decimal:2']; } public function items(): HasMany{return $this->hasMany(PurchaseRequestItem::class);} public function approval(): BelongsTo{return $this->belongsTo(ApprovalRequest::class,'approval_request_id');} public function rfqs(): HasMany{return $this->hasMany(Rfq::class);} }
