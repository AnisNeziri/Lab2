<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'business_name',
        'customer_type',
        'business_registration_number',
        'fiscal_number',
        'is_vat_registered',
        'vat_number',
        'phone',
        'email',
        'address',
        'billing_address',
        'municipality',
        'postal_code',
        'country_code',
        'tax_number',
        'notes',
        'is_active',
        'current_debt',
        'current_credit',
        'credit_limit', 'payment_terms_days', 'credit_status', 'credit_hold_reason', 'credit_hold_at', 'credit_hold_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'current_debt' => 'decimal:2',
            'current_credit' => 'decimal:2',
            'credit_limit' => 'decimal:2', 'payment_terms_days' => 'integer', 'credit_hold_at' => 'datetime',
            'is_active' => 'boolean',
            'is_vat_registered' => 'boolean',
        ];
    }

    public function debtTransactions(): HasMany
    {
        return $this->hasMany(CustomerDebtTransaction::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
