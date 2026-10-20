<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingAccount extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'code', 'name', 'type', 'parent_id', 'normal_balance', 'is_posting', 'is_system', 'is_active', 'cash_flow_class', 'description', 'statement_group'];

    protected function casts(): array
    {
        return ['is_posting' => 'boolean', 'is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        $referenced=fn(self $a)=>TaxCode::query()->where(fn($q)=>$q->where('input_account_id',$a->id)->orWhere('output_account_id',$a->id))->exists();
        static::updating(function(self $a)use($referenced){
            if($a->isDirty(['type','normal_balance','is_posting','is_active'])&&$referenced($a))
                throw \Illuminate\Validation\ValidationException::withMessages(['account'=>'This tax control account is referenced by a versioned tax code. Preserve its accounting semantics.']);
        });
        static::deleting(function(self $a)use($referenced){if($referenced($a))throw \Illuminate\Validation\ValidationException::withMessages(['account'=>'A tax control account referenced by tax history cannot be deleted.']);});
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
