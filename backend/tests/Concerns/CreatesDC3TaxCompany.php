<?php

namespace Tests\Concerns;

trait CreatesDC3TaxCompany
{
    protected function setupTax(): void
    {
        $this->actingAsApiUser();
        $this->putJson('/api/invoice-profile', [
            'legal_name' => 'DC3 Test Company', 'business_registration_number' => '811234567', 'fiscal_number' => '600987654', 'is_vat_registered' => true, 'vat_number' => '330987654',
            'registered_address' => 'Test Street 1', 'municipality' => 'Prishtine', 'postal_code' => '10000', 'country_code' => 'XK', 'phone' => '+38338000000', 'email' => 'test@example.test',
            'invoice_prefix' => 'INV', 'credit_note_prefix' => 'CN', 'default_language' => 'bilingual', 'default_payment_terms_days' => 14, 'sales_mode' => 'business_only',
        ])->assertOk();
        $this->postJson('/api/tax/configuration/initialize')->assertOk();
    }

    protected function invoiceData(int $taxId): array
    {
        return ['invoice_date' => now()->toDateString(), 'supply_date' => now()->toDateString(),
            'buyer' => ['legal_name' => 'Business Buyer', 'business_registration_number' => '812222222', 'address' => 'Buyer Street 1', 'country_code' => 'XK'],
            'items' => [['description' => 'Ordinary service', 'unit' => 'pcs', 'quantity' => '1', 'unit_price' => '100', 'tax_code_id' => $taxId]]];
    }
}
