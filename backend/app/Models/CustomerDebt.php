<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerDebt extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'customer_name'];

    public function entries(): HasMany
    {
        return $this->hasMany(CustomerDebtEntry::class);
    }

    public function getBalanceAttribute(): float
    {
        return round((float) $this->entries()->sum('amount_owed') - (float) $this->entries()->sum('amount_paid'), 2);
    }
}
