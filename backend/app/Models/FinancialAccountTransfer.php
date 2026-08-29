<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAccountTransfer extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'source_account_id', 'destination_account_id', 'amount',
        'transfer_date', 'reference_number', 'notes', 'idempotency_key', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transfer_date' => 'date:Y-m-d'];
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'source_account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_account_id');
    }
}
