<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use LogicException;
class ApprovalDecision extends Model { protected $fillable=['approval_request_id','company_id','actor_id','decision','comment','decided_at','snapshot']; protected function casts(): array { return ['decided_at'=>'datetime','snapshot'=>'array']; } protected static function booted(): void { static::updating(fn () => throw new LogicException('Approval decisions are immutable.')); static::deleting(fn () => throw new LogicException('Approval decisions cannot be deleted.')); } }
