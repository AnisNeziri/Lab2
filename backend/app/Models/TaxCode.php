<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class TaxCode extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'active' => 'boolean', 'sales_enabled' => 'boolean', 'purchases_enabled' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $code) {
            if (array_diff(array_keys($code->getDirty()), ['active', 'updated_at']) && $code->used()) {
                throw ValidationException::withMessages(['tax_code' => 'This rule has document snapshots. Create a new effective-date version instead.']);
            }
        });
        static::deleting(function (self $code) {
            if ($code->used() || Product::where('default_tax_code_id', $code->id)->exists()) {
                throw ValidationException::withMessages(['tax_code' => 'Referenced tax rules cannot be deleted. Retire the rule instead.']);
            }
        });
    }

    public function used(): bool
    {
        return InvoiceItem::where('tax_code_id', $this->id)->exists() || Expense::where('tax_code_id', $this->id)->exists();
    }
}
