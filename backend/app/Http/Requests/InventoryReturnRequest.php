<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryReturnRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $companyId = (int) ($this->user()?->company_id ?? 0);
        $companyExists = static fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);

        return [
            'type' => ['required', Rule::in(['customer', 'supplier'])],
            'customer_id' => ['nullable', 'required_if:type,customer', 'integer', $companyExists('customers')],
            'supplier_id' => ['nullable', 'required_if:type,supplier', 'integer', $companyExists('suppliers')],
            'daily_sale_id' => ['nullable', 'integer', $companyExists('daily_sales')],
            'purchase_order_id' => ['nullable', 'integer', $companyExists('purchase_orders')],
            'goods_receipt_id' => ['nullable', 'integer', $companyExists('goods_receipts')],
            'financial_resolution' => ['required', Rule::in(['none', 'cash_refund', 'debt_credit', 'supplier_credit', 'replacement'])],
            'financial_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'financial_account_id' => ['nullable', 'integer', $companyExists('financial_accounts')],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', $companyExists('products')],
            'items.*.daily_sale_item_id' => ['nullable', 'integer', 'exists:daily_sale_items,id'],
            'items.*.goods_receipt_item_id' => ['nullable', 'integer', 'exists:goods_receipt_items,id'],
            'items.*.warehouse_id' => ['required', 'integer', $companyExists('warehouses')],
            'items.*.location_id' => ['nullable', 'integer', $companyExists('warehouse_locations')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.condition' => ['required', Rule::in(['sellable', 'damaged', 'quarantine', 'scrap'])],
            'items.*.stock_state' => ['nullable', Rule::in(['available', 'damaged', 'quarantine'])],
            'items.*.trace_data' => ['nullable', 'array'],
            'items.*.trace_data.allocations' => ['nullable', 'array', 'max:1000'],
            'items.*.trace_data.allocations.*.inventory_lot_id' => ['nullable', 'integer', $companyExists('inventory_lots')],
            'items.*.trace_data.allocations.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.trace_data.allocations.*.serial_number' => ['nullable', 'string', 'max:191'],
            'items.*.trace_data.allocations.*.supplier_batch' => ['nullable', 'string', 'max:100'],
            'items.*.trace_data.allocations.*.manufactured_at' => ['nullable', 'date'],
            'items.*.trace_data.allocations.*.expiry_at' => ['nullable', 'date'],
            'items.*.trace_data.allocations.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
