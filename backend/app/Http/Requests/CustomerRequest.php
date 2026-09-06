<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'customer_type' => ['sometimes', Rule::in(['business', 'individual'])],
            'business_registration_number' => ['nullable', 'string', 'max:50'],
            'fiscal_number' => ['nullable', 'string', 'max:50'],
            'is_vat_registered' => ['sometimes', 'boolean'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'billing_address' => ['nullable', 'string', 'max:2000'],
            'municipality' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'credit_limit' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'credit_status' => ['sometimes', Rule::in(['normal', 'warning', 'blocked'])],
            'credit_hold_reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf(fn () => $this->input('credit_status') === 'blocked')],
        ];
    }
}
