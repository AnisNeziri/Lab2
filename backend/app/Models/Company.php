<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'automation_settings', 'platform_settings', 'public_id', 'locale', 'security_policy', 'role_permissions', 'capability_settings',
        'name',
        'address',
        'base_currency',
        'fiscal_year_start_month',
        'timezone',
        'operational_cutover_date',
        'accounting_start_date',
        'accounting_opening_finalized_at',
        'accounting_opening_journal_id',
        'supplier_match_quantity_tolerance',
        'supplier_match_price_tolerance',
        'supplier_match_tax_tolerance',
    ];

    protected function casts(): array
    {
        return [
            'automation_settings'=>'array', 'platform_settings'=>'array', 'security_policy'=>'array', 'role_permissions'=>'array', 'capability_settings'=>'array',
            'fiscal_year_start_month' => 'integer',
            'operational_cutover_date' => 'date',
            'accounting_start_date' => 'date',
            'accounting_opening_finalized_at' => 'datetime',
            'accounting_opening_journal_id' => 'integer',
            'supplier_match_quantity_tolerance' => 'decimal:3',
            'supplier_match_price_tolerance' => 'decimal:4',
            'supplier_match_tax_tolerance' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($company) { $company->public_id ??= (string) \Illuminate\Support\Str::uuid(); });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function invoiceProfile(): HasOne
    {
        return $this->hasOne(InvoiceProfile::class);
    }

    public function invoiceSequences(): HasMany
    {
        return $this->hasMany(InvoiceSequence::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
