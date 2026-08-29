<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InvoiceProfile extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'legal_name',
        'trade_name',
        'business_registration_number',
        'fiscal_number',
        'is_vat_registered',
        'vat_number',
        'registered_address',
        'municipality',
        'postal_code',
        'country_code',
        'phone',
        'email',
        'bank_name',
        'bank_account',
        'iban',
        'swift_bic',
        'invoice_prefix',
        'credit_note_prefix',
        'default_language',
        'default_payment_terms_days',
        'default_payment_terms',
        'sales_mode',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'is_vat_registered' => 'boolean',
            'default_payment_terms_days' => 'integer',
        ];
    }
}
