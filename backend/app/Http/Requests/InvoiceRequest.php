<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = Auth::user()?->company_id;

        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'save_customer' => ['sometimes', 'boolean'],
            'issue_now' => ['sometimes', 'boolean'],
            'buyer' => ['required_without:customer_id', 'nullable', 'array'],
            'buyer.legal_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'buyer.trade_name' => ['nullable', 'string', 'max:255'],
            'buyer.business_registration_number' => ['nullable', 'string', 'max:50'],
            'buyer.fiscal_number' => ['nullable', 'string', 'max:50'],
            'buyer.is_vat_registered' => ['nullable', 'boolean'],
            'buyer.vat_number' => ['nullable', 'string', 'max:50'],
            'buyer.address' => ['nullable', 'string', 'max:2000'],
            'buyer.municipality' => ['nullable', 'string', 'max:255'],
            'buyer.postal_code' => ['nullable', 'string', 'max:20'],
            'buyer.country_code' => ['nullable', 'string', 'size:2'],
            'buyer.phone' => ['nullable', 'string', 'max:50'],
            'buyer.email' => ['nullable', 'email', 'max:255'],
            'invoice_date' => ['required', 'date'],
            'supply_date' => ['required', 'date'],
            'supply_time' => ['nullable', 'date_format:H:i'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'payment_terms' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'fiscal_receipt_number' => ['nullable', 'string', 'max:100'],
            'external_fiscal_code' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:250'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000'],
            'items.*.actual_base_quantity' => ['nullable', 'numeric', 'min:0.001', 'max:1000000000'],
            'items.*.warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'items.*.location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', $companyId)],
            'items.*.trace_allocations' => ['nullable', 'array', 'max:1000'],
            'items.*.trace_allocations.*.inventory_lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')->where('company_id', $companyId)],
            'items.*.trace_allocations.*.serial_number' => ['nullable', 'string', 'max:191'],
            'items.*.trace_allocations.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.trace_allocations.*.expiry_at' => ['nullable', 'date'],
            'items.*.trace_allocations.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000000000'],
            'items.*.vat_rate' => ['nullable', Rule::in([0, 8, 18, '0', '8', '18'])],
            'items.*.tax_treatment' => ['nullable', Rule::in(['standard', 'zero_rated', 'exempt', 'reverse_charge', 'non_vat'])],
            'items.*.tax_legal_reference' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
