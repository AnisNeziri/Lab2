<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Services\Kosovo\CorporateTaxReviewService;
use App\Services\Kosovo\CorporateTaxDispositionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateTaxReviewController extends Controller
{
    public function __construct(
        private readonly CorporateTaxReviewService $reviews,
        private readonly CorporateTaxDispositionService $dispositions,
    ) {}

    public function disposition(AccountingPeriod $period): JsonResponse
    {
        return response()->json([
            ...$this->reviews->readiness($period),
            'decision' => $this->dispositions->latest($period),
        ]);
    }

    public function approveDisposition(Request $request, AccountingPeriod $period): JsonResponse
    {
        $data = $request->validate([
            'regime' => 'required|in:actual_income,inapplicable,alternative_reviewed,unresolved,unsupported',
            'regime_name' => 'required|string|min:5|max:160',
            'jurisdiction' => 'required|string|regex:/^[A-Za-z]{2}$/',
            'effective_from' => 'required|date_format:Y-m-d',
            'effective_to' => 'required|date_format:Y-m-d|after_or_equal:effective_from',
            'policy_reference' => 'required|string|min:5|max:500',
            'reason' => 'required|string|min:10|max:1000',
            'recorded_tax' => 'required_if:regime,alternative_reviewed|nullable|numeric|min:0|max:99999999999999|decimal:0,2',
        ]);

        return response()->json($this->dispositions->approve($period, $data), 201);
    }

    public function show(AccountingPeriod $period): JsonResponse
    {
        $review = $this->reviews->latest($period);

        return $review ? response()->json($review) : response()->json(null, 204);
    }

    public function preview(Request $request, AccountingPeriod $period): JsonResponse
    {
        return response()->json($this->reviews->preview($period, $request->validate($this->rules())));
    }

    public function approve(Request $request, AccountingPeriod $period): JsonResponse
    {
        $data = $request->validate([...$this->rules(), 'fingerprint' => 'required|string|size:64']);

        return response()->json($this->reviews->approve($period, $data), 201);
    }

    private function rules(): array
    {
        return [
            'rate_percent' => 'required|numeric|min:0|max:100|decimal:0,4',
            'policy_reference' => 'required|string|min:5|max:500',
            'adjustments' => 'nullable|array|max:150',
            'adjustments.*' => 'required|array:direction,amount,reason,journal_entry_id',
            'adjustments.*.direction' => 'required|in:add,deduct',
            'adjustments.*.amount' => 'required|numeric|gt:0|max:99999999999999|decimal:0,2',
            'adjustments.*.reason' => 'required|string|min:5|max:500',
            'adjustments.*.journal_entry_id' => 'nullable|integer|min:1',
        ];
    }
}
