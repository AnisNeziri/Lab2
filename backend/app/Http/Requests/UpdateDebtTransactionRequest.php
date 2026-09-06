<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDebtTransactionRequest extends FormRequest
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
            'payment_method' => ['nullable', Rule::in(['cash', 'bank_transfer', 'card', 'cheque', 'other'])],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'approval_request_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
