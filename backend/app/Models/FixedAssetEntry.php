<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'fixed_asset_id', 'expense_id', 'sale_invoice_id', 'journal_entry_id', 'reverses_entry_id', 'kind', 'effective_on',
        'cost_delta', 'depreciation_delta', 'impairment_delta', 'request_key', 'request_hash', 'reason', 'created_by',
    ];

    protected $hidden = ['request_hash', 'request_key'];

    protected $casts = [
        'effective_on' => 'date:Y-m-d', 'cost_delta' => 'decimal:2',
        'depreciation_delta' => 'decimal:2', 'impairment_delta' => 'decimal:2',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class)->withTrashed();
    }

    public function saleInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'sale_invoice_id');
    }
}
