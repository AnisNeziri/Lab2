<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class ApprovalRule extends Model { use BelongsToCompany; protected $fillable=['company_id','rule_type','threshold_amount','currency','required_role','required_user_id','separation_of_duties','is_active']; protected function casts(): array { return ['threshold_amount'=>'decimal:2','separation_of_duties'=>'boolean','is_active'=>'boolean']; } }
