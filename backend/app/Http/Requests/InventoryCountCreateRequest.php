<?php

namespace App\Http\Requests;

use App\Services\WarehouseInventoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class InventoryCountCreateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $companyId = Auth::user()->company_id;

        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'stock_states' => ['nullable', 'array', 'min:1', 'max:5'],
            'stock_states.*' => ['required', 'distinct', Rule::in(WarehouseInventoryService::STATES)],
            'product_ids' => ['nullable', 'array', 'max:1000'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
