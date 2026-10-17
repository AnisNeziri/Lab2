<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'exchange_rate'=>'nullable|numeric|gt:0|max:1000000',
            'exchange_rate_date'=>'nullable|date|before_or_equal:payment_date',
            'exchange_rate_source'=>'nullable|string|max:255',
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'cheque', 'other'])],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'financial_account_id' => [
                'nullable', 'integer',
                Rule::exists('financial_accounts', 'id')->where('company_id', $this->user()?->company_id),
            ],
            'expense_id' => [
                'nullable',
                'integer',
                Rule::exists('expenses', 'id')->where(fn ($query) => $query
                    ->where('company_id', $this->user()?->company_id)
                    ->where('document_type', 'purchase_invoice')
                    ->where('status', '!=', 'reversed')
                    ->whereNull('deleted_at')),
            ],
        ];
    }
}
