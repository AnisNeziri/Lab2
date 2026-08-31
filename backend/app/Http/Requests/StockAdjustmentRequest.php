<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\WarehouseLocation;
use App\Services\WarehouseInventoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $companyId = (int) Auth::user()->company_id;

        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId),
            ],
            'type' => ['required', Rule::in(['in', 'out'])],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where(
                    fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)
                ),
            ],
            'location_id' => [
                'required',
                'integer',
                Rule::exists('warehouse_locations', 'id')->where(
                    fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)
                ),
            ],
            'stock_state' => ['nullable', Rule::in(WarehouseInventoryService::STATES)],
            'allow_expired_override' => ['nullable', 'boolean'],
            'expired_override_reason' => [
                'nullable',
                'required_if:allow_expired_override,true',
                'string',
                'min:5',
                'max:1000',
            ],
            'trace_allocations' => ['nullable', 'array', 'max:1000'],
            'trace_allocations.*.inventory_lot_id' => [
                'nullable',
                'integer',
                Rule::exists('inventory_lots', 'id')->where('company_id', $companyId),
            ],
            'trace_allocations.*.lot_number' => ['nullable', 'string', 'max:100'],
            'trace_allocations.*.serial_number' => ['nullable', 'string', 'max:191'],
            'trace_allocations.*.supplier_batch' => ['nullable', 'string', 'max:100'],
            'trace_allocations.*.manufactured_at' => ['nullable', 'date'],
            'trace_allocations.*.expiry_at' => ['nullable', 'date'],
            'trace_allocations.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['product_id', 'warehouse_id', 'location_id'])) {
                return;
            }

            $companyId = (int) $this->user()->company_id;
            $locationMatchesWarehouse = WarehouseLocation::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('warehouse_id', (int) $this->input('warehouse_id'))
                ->whereKey((int) $this->input('location_id'))
                ->where('is_active', true)
                ->exists();
            if (! $locationMatchesWarehouse) {
                $validator->errors()->add('location_id', 'Select an active location inside the selected warehouse.');

                return;
            }

            $product = Product::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->find((int) $this->input('product_id'));
            if (! $product) {
                return;
            }

            $allocations = $this->input('trace_allocations', []);
            $allocations = is_array($allocations) ? $allocations : [];
            if (($product->tracking_mode ?: 'none') !== 'none' && $allocations === []) {
                $validator->errors()->add(
                    'trace_allocations',
                    $product->tracking_mode === 'serial'
                        ? 'Select every serial number included in this adjustment.'
                        : 'Enter the lot or batch allocation for this adjustment.',
                );
            }
            if (($product->tracking_mode ?: 'none') === 'none' && $allocations !== []) {
                $validator->errors()->add('trace_allocations', 'This product does not use lot or serial tracking.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $this->merge(['reason' => trim((string) $this->input('reason'))]);
        }
    }
}
