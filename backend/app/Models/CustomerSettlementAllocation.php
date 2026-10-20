<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CustomerSettlementAllocation extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'allocated_on' => 'date', 'released_on' => 'date', 'released_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function($m){
            $count=CustomerDebtTransaction::query()->where('customer_id',$m->customer_id)->whereIn('id',[$m->settlement_transaction_id,$m->debt_transaction_id])->count();
            if($count!==2 || !Customer::query()->whereKey($m->customer_id)->exists())throw new \LogicException('An allocation requires two distinct source records from the same customer and company.');
        });
        static::updating(function ($m) {
            if ($m->getOriginal('released_at') || $m->getOriginal('released_on') || !$m->released_at || !$m->released_on || !$m->released_by || !filled($m->release_reason)
                || $m->released_on->lt($m->allocated_on) || array_diff(array_keys($m->getDirty()), ['released_on', 'released_at', 'released_by', 'release_reason', 'updated_at'])) {
                throw new \LogicException('Allocation history is immutable; release and allocate again.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Allocation history cannot be deleted.'));
    }

    public function settlement()
    {
        return $this->belongsTo(CustomerDebtTransaction::class, 'settlement_transaction_id');
    }

    public function debt()
    {
        return $this->belongsTo(CustomerDebtTransaction::class, 'debt_transaction_id');
    }

    public function allocator()
    {
        return $this->belongsTo(User::class, 'allocated_by')->withTrashed();
    }

    public function releaser()
    {
        return $this->belongsTo(User::class, 'released_by')->withTrashed();
    }
}
