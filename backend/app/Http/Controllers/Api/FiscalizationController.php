<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\Sef\FiscalizationService;
use App\Integrations\Sef\SefConfigurationService;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\FiscalReceipt;
use App\Models\Invoice;
use App\Models\SefMapping;
use App\Services\FiscalWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FiscalizationController extends Controller
{
    public function summary(FiscalWorkspace $w)
    {
        return response()->json($w->summary());
    }

    public function candidates(Request $r, FiscalWorkspace $w)
    {
        return response()->json($w->candidates($r->validate(['search' => 'nullable|string|max:100', 'invoice' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1'])));
    }

    public function show(FiscalReceipt $fiscalReceipt, FiscalWorkspace $w)
    {
        return response()->json($w->receipt($fiscalReceipt));
    }

    public function prepare(Invoice $invoice, FiscalizationService $s)
    {
        return response()->json($s->prepare($invoice), 201);
    }

    public function submit(Request $r, FiscalReceipt $fiscalReceipt, FiscalizationService $s)
    {
        $r->validate(['confirm_test_submission' => 'required|accepted']);

        return response()->json($s->submit($fiscalReceipt));
    }

    public function review(Request $r, FiscalReceipt $fiscalReceipt, FiscalizationService $s)
    {
        $d = $r->validate(['reason' => 'required|string|min:10|max:1000']);

        return response()->json($s->review($fiscalReceipt, $d['reason']));
    }

    public function configure(Request $r, SefConfigurationService $s)
    {
        $d = $r->validate(['environment' => ['required', Rule::in(['test'])], 'branch_id' => 'required|regex:/^[1-9][0-9]{0,18}$/', 'pos_id' => 'required|regex:/^[1-9][0-9]{0,18}$/',
            'application_id' => 'required|regex:/^[1-9][0-9]{0,18}$/', 'location' => 'required|string|max:200', 'activity_policy' => ['required', Rule::in(['business_only', 'retail', 'needs_review'])],
            'policy_basis' => 'nullable|string|max:1000', 'signature_format' => ['required', Rule::in(['ieee_p1363', 'der'])], 'enabled' => 'required|boolean', 'starting_coupon_no' => 'nullable|regex:/^[1-9][0-9]{0,17}$/']);

        return response()->json($s->configure($d));
    }

    public function mappings()
    {
        return response()->json(['mappings' => SefMapping::orderBy('kind')->orderBy('local_key')->get(), 'catalog' => json_decode(file_get_contents(resource_path('sef/catalog.json')), true)]);
    }

    public function saveMapping(Request $r)
    {
        $d = $r->validate(['kind' => ['required', Rule::in(['unit', 'category'])], 'local_key' => 'required|string|max:80', 'official_id' => 'required|integer|min:1']);
        $catalog = json_decode(file_get_contents(resource_path('sef/catalog.json')), true);
        $row = collect($catalog[$d['kind'] === 'unit' ? 'units' : 'categories'])->firstWhere('id', $d['official_id']);
        if (! $row || ($d['kind'] === 'category' && isset($catalog['ambiguous_category_codes'][$row['code']]))) {
            throw ValidationException::withMessages(['official_id' => 'Choose an unambiguous official catalog entry.']);
        }
        if ($d['kind'] === 'category') {
            Category::findOrFail($d['local_key']);
        }
        $m = SefMapping::updateOrCreate(['kind' => $d['kind'], 'local_key' => $d['local_key']], ['official_id' => $d['official_id']]);
        ActivityLog::create(['company_id' => $r->user()->company_id, 'user_id' => $r->user()->id, 'action' => 'fiscalization.mapping.changed', 'entity' => 'SefMapping', 'entity_id' => $m->id, 'description' => 'SEF mapping changed', 'new_value' => $d]);

        return response()->json($m);
    }
}
