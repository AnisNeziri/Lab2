<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerDebtEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'transaction_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:transaction_date'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'opening_balance' => ['sometimes', 'boolean'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'approval_request_id' => ['nullable', 'integer', 'min:1'],
            'source_entity' => ['nullable', 'string', 'max:120'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'array'],
            'source.entity_type' => ['required_with:source', 'string', 'max:120'],
            'source.reference' => ['required_with:source', 'string', 'max:255'],
        ];
    }
}
