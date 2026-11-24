<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Services\FxRevaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FxRevaluationController extends Controller
{
    public function __construct(private readonly FxRevaluationService $revaluations) {}

    public function preview(Request $request, AccountingPeriod $period): JsonResponse
    {
        $data = $request->validate($this->rateRules());

        return response()->json($this->revaluations->preview($period, ($data['rates'] ?? []) ?: $this->revaluations->savedRates($period)));
    }

    public function approve(Request $request, AccountingPeriod $period): JsonResponse
    {
        $data = $request->validate([...$this->rateRules(),
            'fingerprint' => 'required|string|size:64', 'reason' => 'required|string|min:5|max:1000',
        ]);

        return response()->json($this->revaluations->approve($period, $data), 201);
    }

    private function rateRules(): array
    {
        return [
            'rates' => 'nullable|array|max:20',
            'rates.*' => 'required|array:exchange_rate,exchange_rate_date,exchange_rate_source',
            'rates.*.exchange_rate' => 'required|numeric|gt:0|max:1000000|decimal:0,8',
            'rates.*.exchange_rate_date' => 'required|date_format:Y-m-d',
            'rates.*.exchange_rate_source' => 'required|string|max:255',
        ];
    }
}
