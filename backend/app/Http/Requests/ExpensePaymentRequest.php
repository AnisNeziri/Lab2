<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpensePaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:9000000000000'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'exchange_rate_date' => ['nullable', 'date'],
            'exchange_rate_source' => ['nullable', 'string', 'max:255'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'cheque', 'other'])],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
            'financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
        ];
    }
}
