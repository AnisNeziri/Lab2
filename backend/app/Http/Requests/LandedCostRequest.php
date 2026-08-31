<?php

namespace App\Http\Requests;

use App\Services\LandedCostService;
use App\Support\CompanyCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class LandedCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = Auth::user()->company_id;
        $baseCurrency = CompanyCurrency::forCompanyId((int) $companyId);

        return [
            'goods_receipt_id' => ['required', 'integer', Rule::exists('goods_receipts', 'id')->where('company_id', $companyId)],
            'shipment_id' => ['nullable', 'integer', Rule::exists('shipments', 'id')->where('company_id', $companyId)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'cost_type' => ['required', Rule::in(LandedCostService::COST_TYPES)],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'currency' => ['required', Rule::in(CompanyCurrency::accepted($baseCurrency))],
            'exchange_rate_to_base' => [
                Rule::requiredIf(fn () => strtoupper((string) $this->input('currency')) !== $baseCurrency),
                'nullable', 'numeric', 'gt:0', 'max:1000000',
            ],
            'exchange_rate_date' => [
                Rule::requiredIf(fn () => strtoupper((string) $this->input('currency')) !== $baseCurrency),
                'nullable', 'date',
            ],
            'allocation_method' => ['required', Rule::in(LandedCostService::ALLOCATION_METHODS)],
            'goods_receipt_item_ids' => ['nullable', 'array', 'min:1'],
            'goods_receipt_item_ids.*' => ['integer', 'distinct'],
            'allocations' => [Rule::requiredIf(fn () => $this->input('allocation_method') === 'manual'), 'nullable', 'array', 'min:1'],
            'allocations.*.goods_receipt_item_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
