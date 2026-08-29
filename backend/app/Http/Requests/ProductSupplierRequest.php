<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProductSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = Auth::user()->company_id;
        $creating = $this->isMethod('post');

        return [
            'product_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'supplier_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'supplier_sku' => ['nullable', 'string', 'max:100'],
            'purchase_price' => [$creating ? 'nullable' : 'sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'currency' => [$creating ? 'required' : 'sometimes', Rule::in(['EUR', 'USD', 'ALL', 'GBP', 'CNY'])],
            'exchange_rate_to_base' => [
                Rule::requiredIf(fn () => $this->filled('currency') && strtoupper((string) $this->input('currency')) !== 'EUR'),
                'nullable', 'numeric', 'gt:0', 'max:1000000',
            ],
            'pack_size' => ['sometimes', 'numeric', 'min:0.001', 'max:999999999999'],
            'minimum_order_quantity' => ['sometimes', 'numeric', 'min:0', 'max:999999999999'],
            'usual_lead_time_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'is_preferred' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'supplier_description' => ['nullable', 'string', 'max:4000'],
            'price_effective_at' => ['nullable', 'date'],
            'price_change_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
