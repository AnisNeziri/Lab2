<?php

namespace App\Services;

use App\Models\InvoiceProfile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class InvoiceProfileService
{
    public function getForCurrentCompany(): array
    {
        $this->requireCompanyId();
        $company = Auth::user()->company;
        $profile = InvoiceProfile::query()->first();

        if (! $profile) {
            $profile = new InvoiceProfile([
                'company_id' => $company->id,
                'legal_name' => $company->name,
                'registered_address' => $company->address,
                'country_code' => 'XK',
                'invoice_prefix' => 'INV',
                'credit_note_prefix' => 'CN',
                'default_language' => 'bilingual',
                'default_payment_terms_days' => 14,
                'sales_mode' => 'business_only',
                'is_vat_registered' => false,
            ]);
        }

        return $this->response($profile);
    }

    public function updateForCurrentCompany(array $data): array
    {
        $this->requireCompanyId();
        if (strcasecmp(trim((string) $data['invoice_prefix']), trim((string) $data['credit_note_prefix'])) === 0) {
            throw ValidationException::withMessages([
                'credit_note_prefix' => ['The credit-note prefix must be different from the invoice prefix.'],
            ]);
        }
        if (! ($data['is_vat_registered'] ?? false)) {
            $data['vat_number'] = null;
        }

        $data['country_code'] = strtoupper($data['country_code']);
        $data['invoice_prefix'] = strtoupper($data['invoice_prefix']);
        $data['credit_note_prefix'] = strtoupper($data['credit_note_prefix']);
        // AIMS currently produces B2B tax documents only. Consumer/mixed mode
        // must remain disabled until a certified EFS/fiscalization integration
        // can verify official fiscal receipt identifiers.
        $data['sales_mode'] = 'business_only';

        $profile = InvoiceProfile::query()->updateOrCreate(
            ['company_id' => Auth::user()->company_id],
            $data,
        );

        return $this->response($profile->fresh());
    }

    public function missingRequiredFields(InvoiceProfile $profile): array
    {
        $fields = [
            'legal_name',
            'business_registration_number',
            'fiscal_number',
            'registered_address',
            'municipality',
            'country_code',
            'invoice_prefix',
            'credit_note_prefix',
        ];

        if ($profile->is_vat_registered) {
            $fields[] = 'vat_number';
        }
        if (strcasecmp((string) $profile->invoice_prefix, (string) $profile->credit_note_prefix) === 0) {
            $fields[] = 'credit_note_prefix (must differ from invoice_prefix)';
        }

        return array_values(array_filter(
            $fields,
            fn (string $field) => blank($profile->{$field}),
        ));
    }

    public function sellerSnapshot(InvoiceProfile $profile): array
    {
        return [
            'legal_name' => $profile->legal_name,
            'trade_name' => $profile->trade_name,
            'business_registration_number' => $profile->business_registration_number,
            'fiscal_number' => $profile->fiscal_number,
            'is_vat_registered' => (bool) $profile->is_vat_registered,
            'vat_number' => $profile->vat_number,
            'registered_address' => $profile->registered_address,
            'municipality' => $profile->municipality,
            'postal_code' => $profile->postal_code,
            'country_code' => $profile->country_code,
            'phone' => $profile->phone,
            'email' => $profile->email,
            'bank_name' => $profile->bank_name,
            'bank_account' => $profile->bank_account,
            'iban' => $profile->iban,
            'swift_bic' => $profile->swift_bic,
            'sales_mode' => $profile->sales_mode,
        ];
    }

    private function response(InvoiceProfile $profile): array
    {
        $missing = $profile->exists ? $this->missingRequiredFields($profile) : [
            'business_registration_number', 'fiscal_number', 'municipality',
        ];

        return [
            'profile' => $profile,
            'completeness' => [
                'complete' => $profile->exists && $missing === [],
                'missing' => $missing,
            ],
        ];
    }

    private function requireCompanyId(): int
    {
        $companyId = Auth::user()?->company_id;
        abort_unless($companyId, 403, 'Select an explicit company context before using company invoice data.');

        return (int) $companyId;
    }
}
