<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = Auth::user()->company_id;

        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'ordered_at' => ['required', 'date'],
            'expected_at' => ['nullable', 'date', 'after_or_equal:ordered_at'],
            'due_at' => ['nullable', 'date', 'after_or_equal:ordered_at'],
            'currency' => ['required', Rule::in(['EUR', 'USD', 'ALL', 'GBP', 'CNY'])],
            'exchange_rate' => [Rule::requiredIf(fn () => strtoupper((string) $this->input('currency')) !== 'EUR'), 'nullable', 'numeric', 'gt:0', 'max:1000000'],
            'exchange_rate_date' => [Rule::requiredIf(fn () => strtoupper((string) $this->input('currency')) !== 'EUR'), 'nullable', 'date'],
            'exchange_rate_source' => [Rule::requiredIf(fn () => strtoupper((string) $this->input('currency')) !== 'EUR'), 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['draft', 'confirmed', 'ordered'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
