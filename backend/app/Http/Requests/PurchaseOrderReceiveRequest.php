<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PurchaseOrderReceiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = Auth::user()->company_id;

        return [
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', $companyId)],
            'received_at' => ['nullable', 'date'],
            'supplier_document_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.accepted_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.damaged_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.rejected_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.accepted_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.damaged_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.trace_allocations' => ['nullable', 'array', 'max:1000'],
            'items.*.trace_allocations.*.inventory_lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')->where('company_id', $companyId)],
            'items.*.trace_allocations.*.stock_state' => ['required', Rule::in(['available', 'damaged'])],
            'items.*.trace_allocations.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.trace_allocations.*.serial_number' => ['nullable', 'string', 'max:191'],
            'items.*.trace_allocations.*.supplier_batch' => ['nullable', 'string', 'max:100'],
            'items.*.trace_allocations.*.manufactured_at' => ['nullable', 'date'],
            'items.*.trace_allocations.*.expiry_at' => ['nullable', 'date'],
            'items.*.trace_allocations.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
