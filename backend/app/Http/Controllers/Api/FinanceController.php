<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FinanceController extends Controller
{
    public function __construct(private readonly FinanceService $finance) {}

    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json($this->finance->overview($from, $to));
    }

    public function vatBooks(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json($this->finance->vatBooks($from, $to));
    }

    public function cashFlow(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json($this->finance->cashFlow($from, $to));
    }

    public function receivablesAging(Request $request): JsonResponse
    {
        $data = $request->validate(['date_to' => ['nullable', 'date'], 'as_of' => ['nullable', 'date']]);

        return response()->json($this->finance->receivablesAging($data['as_of'] ?? $data['date_to'] ?? now()->toDateString()));
    }

    public function exportVatBooks(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $data = $request->validate([
            'format' => ['nullable', 'in:xlsx'],
            'locale' => ['nullable', 'in:en,sq,bilingual'],
        ]);
        $bytes = $this->finance->vatWorkbook($from, $to, $data['locale'] ?? 'bilingual');
        $filename = "kosovo-vat-books-{$from}-to-{$to}.xlsx";

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function period(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $from = $data['date_from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['date_to'] ?? now()->endOfMonth()->toDateString();

        return [$from, $to];
    }
}
