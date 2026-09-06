<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ApprovalRequest extends Model { use BelongsToCompany; protected $fillable=['company_id','entity_type','entity_id','rule_type','requested_by','requested_at','required_user_id','required_role','status','decided_by','decided_at','decision_comment','requested_amount','currency','context']; protected function casts(): array { return ['requested_at'=>'datetime','decided_at'=>'datetime','requested_amount'=>'decimal:2','context'=>'array']; } public function decisions(): HasMany { return $this->hasMany(ApprovalDecision::class); } }
