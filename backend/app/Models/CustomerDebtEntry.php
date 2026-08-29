<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDebtEntry extends Model
{
    protected $fillable = ['customer_debt_id', 'entry_date', 'description', 'amount_owed', 'amount_paid', 'notes'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'amount_owed' => 'decimal:2', 'amount_paid' => 'decimal:2'];
    }

    public function customerDebt(): BelongsTo
    {
        return $this->belongsTo(CustomerDebt::class);
    }
}
