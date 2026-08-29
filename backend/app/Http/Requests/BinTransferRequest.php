<?php

namespace App\Http\Requests;

use App\Services\WarehouseInventoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class BinTransferRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $companyId = Auth::user()->company_id;

        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'source_location_id' => ['required', 'integer', Rule::exists('warehouse_locations', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'destination_location_id' => ['required', 'integer', 'different:source_location_id', Rule::exists('warehouse_locations', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
            'stock_state' => ['nullable', Rule::in(WarehouseInventoryService::STATES)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
            'trace_allocations' => ['nullable', 'array', 'max:1000'],
            'trace_allocations.*.inventory_lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')->where('company_id', $companyId)],
            'trace_allocations.*.lot_number' => ['nullable', 'string', 'max:100'],
            'trace_allocations.*.serial_number' => ['nullable', 'string', 'max:191'],
            'trace_allocations.*.expiry_at' => ['nullable', 'date'],
            'trace_allocations.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
        ];
    }
}
