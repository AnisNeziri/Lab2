<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvoiceProfile;
use App\Models\Product;
use App\Models\TaxCode;
use App\Services\TaxCodeService;
use App\Support\CompanyClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxConfigurationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['codes' => TaxCode::orderBy('code')->orderByDesc('effective_from')->get(),
            'required_from' => InvoiceProfile::query()->value('tax_codes_required_from'), 'treatments' => TaxCodeService::TREATMENTS,
            'qualification' => 'Template rates do not classify goods. An accountant must confirm category, legal basis and credit rights.']);
    }

    public function initialize(TaxCodeService $service): JsonResponse
    {
        return response()->json(['codes' => $service->initialize()]);
    }

    public function store(Request $r, TaxCodeService $service): JsonResponse
    {
        return response()->json($service->save($this->data($r)), 201);
    }

    public function update(Request $r, TaxCode $taxCode, TaxCodeService $service): JsonResponse
    {
        return response()->json($service->save($this->data($r), $taxCode));
    }

    public function retire(TaxCode $taxCode): JsonResponse
    {
        $taxCode->update(['active' => false]);

        return response()->json($taxCode->fresh());
    }

    public function product(Request $r, Product $product): JsonResponse
    {
        $d = $r->validate(['default_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $r->user()->company_id)]]);
        $product->update($d);

        return response()->json($product->only(['id', 'name', 'default_tax_code_id']));
    }

    public function policy(Request $r): JsonResponse
    {
        $date = CompanyClock::today()->toDateString();
        $d = $r->validate(['tax_codes_required_from' => ['required', 'date', 'after_or_equal:'.$date]]);
        $profile = InvoiceProfile::query()->firstOrFail();
        $profile->update($d);

        return response()->json(['required_from' => $profile->tax_codes_required_from]);
    }

    private function data(Request $r): array
    {
        return $r->validate([
            'code' => 'required|string|max:40|regex:/^[A-Z0-9_-]+$/', 'name' => 'required|string|max:160', 'rate' => 'required|numeric|min:0|max:100',
            'treatment' => ['required', Rule::in(TaxCodeService::TREATMENTS)], 'sales_enabled' => 'required|boolean', 'purchases_enabled' => 'required|boolean',
            'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from', 'active' => 'required|boolean',
            'output_account_id' => ['nullable', 'integer', Rule::exists('accounting_accounts', 'id')->where('company_id', $r->user()->company_id)],
            'input_account_id' => ['nullable', 'integer', Rule::exists('accounting_accounts', 'id')->where('company_id', $r->user()->company_id)],
            'sef_tax_group' => ['nullable', Rule::in(['A', 'C', 'D', 'E'])], 'legal_reference' => 'nullable|string|max:1000',
        ]);
    }
}
