<?php

namespace App\Http\Requests;

use App\Services\WarehouseInventoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class InventoryCountRecordRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $companyId = Auth::user()->company_id;

        return [
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.count_item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required_without:items.*.count_item_id', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', $companyId)],
            'items.*.stock_state' => ['nullable', Rule::in(WarehouseInventoryService::STATES)],
            'items.*.inventory_lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')->where('company_id', $companyId)],
            'items.*.counted_quantity' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'items.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.serial_number' => ['nullable', 'string', 'max:191'],
            'items.*.supplier_batch' => ['nullable', 'string', 'max:100'],
            'items.*.manufactured_at' => ['nullable', 'date'],
            'items.*.expiry_at' => ['nullable', 'date'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
