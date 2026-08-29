<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InvoiceProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'business_registration_number' => ['required', 'string', 'max:50'],
            'fiscal_number' => ['required', 'string', 'max:50'],
            'is_vat_registered' => ['required', 'boolean'],
            'vat_number' => [Rule::requiredIf($this->boolean('is_vat_registered')), 'nullable', 'string', 'max:50'],
            'registered_address' => ['required', 'string', 'max:2000'],
            'municipality' => ['required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['required', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:100'],
            'iban' => ['nullable', 'string', 'max:34'],
            'swift_bic' => ['nullable', 'string', 'max:11'],
            'invoice_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'credit_note_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'default_language' => ['required', Rule::in(['sq', 'en', 'bilingual'])],
            'default_payment_terms_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'default_payment_terms' => ['nullable', 'string', 'max:2000'],
            'sales_mode' => ['required', Rule::in(['business_only'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $invoicePrefix = trim((string) $this->input('invoice_prefix'));
            $creditPrefix = trim((string) $this->input('credit_note_prefix'));
            if ($invoicePrefix !== '' && $creditPrefix !== '' && strcasecmp($invoicePrefix, $creditPrefix) === 0) {
                $validator->errors()->add(
                    'credit_note_prefix',
                    'The credit-note prefix must be different from the invoice prefix.'
                );
            }
        });
    }
}
