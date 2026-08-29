<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_name' => ['required', 'string', 'max:255'],
            'vendor_business_number' => ['nullable', 'string', 'max:50'],
            'vendor_fiscal_number' => ['nullable', 'string', 'max:50'],
            'vendor_vat_number' => ['nullable', 'string', 'max:50'],
            'document_type' => ['required', Rule::in(['purchase_invoice', 'fiscal_receipt', 'credit_note', 'customs_document', 'other'])],
            'document_number' => ['required', 'string', 'max:100'],
            'original_document_number' => ['nullable', 'string', 'max:100'],
            'source_type' => ['required', Rule::in(['domestic', 'import'])],
            'asset_treatment' => ['required', Rule::in(['ordinary', 'investment'])],
            'category' => ['required', Rule::in(['inventory', 'rent', 'utilities', 'transport', 'salaries', 'professional_services', 'marketing', 'advertising', 'representation', 'bank_fees', 'insurance', 'vehicle_fuel', 'fines_penalties', 'donations', 'capital_asset', 'stock_loss', 'maintenance', 'taxes_fees', 'travel', 'office', 'other'])],
            'description' => ['nullable', 'string', 'max:5000'],
            'business_purpose' => ['required_unless:category,inventory', 'nullable', 'string', 'max:2000'],
            'invoice_date' => ['required', 'date'],
            'received_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'supply_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'currency' => ['nullable', Rule::in(['EUR', 'USD', 'GBP', 'CHF', 'ALL', 'RSD', 'TRY', 'CNY', 'eur', 'usd', 'gbp', 'chf', 'all', 'rsd', 'try', 'cny'])],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'exchange_rate_date' => ['nullable', 'date'],
            'exchange_rate_source' => ['nullable', 'string', 'max:255'],
            'net_amount' => ['required', 'numeric', 'min:0', 'max:9000000000000'],
            'vat_rate' => ['required', Rule::in([0, 8, 18, '0', '8', '18'])],
            'vat_amount' => ['required', 'numeric', 'min:0', 'max:9000000000000'],
            'self_assessed_vat_amount' => ['nullable', 'numeric', 'min:0', 'max:9000000000000'],
            'vat_treatment' => ['required', Rule::in(['standard', 'reduced', 'exempt', 'reverse_charge', 'non_vat', 'import_vat'])],
            'input_vat_eligible' => ['required', 'boolean'],
            'deductible_vat_amount' => ['nullable', 'numeric', 'min:0', 'max:9000000000000'],
            'tax_legal_reference' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
