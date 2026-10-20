<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VatReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VatReportingController extends Controller
{
    public function period(Request $r, VatReportingService $service): JsonResponse
    {
        $d = $r->validate(['period' => 'required|date_format:Y-m', 'carried_credit' => 'nullable|numeric|min:0|max:9000000000000', 'refund_requested' => 'nullable|numeric|min:0|max:9000000000000']);

        return response()->json($service->period($d['period'], isset($d['carried_credit']) ? (string) $d['carried_credit'] : null, isset($d['refund_requested']) ? (string) $d['refund_requested'] : null));
    }

    public function reconciliation(Request $r, VatReportingService $service): JsonResponse
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        return response()->json($service->reconciliation($d['from'] ?? null, $d['to'] ?? null));
    }

    public function documents(Request $r, VatReportingService $service): JsonResponse
    {
        $d = $r->validate(['period' => 'required|date_format:Y-m', 'book' => ['required', Rule::in(['sales', 'purchases', 'output', 'input'])], 'page' => 'nullable|integer|min:1', 'box' => 'nullable|integer|min:9|max:72']);

        return response()->json($service->documents($d['period'], $d['book'], $d['page'] ?? 1, isset($d['box']) ? $this->base((int) $d['box']) : null));
    }

    public function export(Request $r, VatReportingService $service)
    {
        $d = $r->validate(['period' => 'required|date_format:Y-m']);
        $report = $service->period($d['period']);

        return response()->streamDownload(function () use ($report) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['ATK TV preparation preview', 'NOT FILED', $report['period']]);
            fputcsv($f, ['Field', 'Description', 'Known mapped amount EUR', 'Complete', 'Source lines']);
            foreach ($report['rows'] as $row) {
                fputcsv($f, [$row['number'], $row['label'], $row['amount'] ?? 'REVIEW REQUIRED', $row['complete'] ? 'yes' : 'no', $row['source_count']]);
            }
            fputcsv($f, ['VAT/GL reconciliation', $report['reconciliation']['reconciled'] ? 'reconciled' : 'difference']);
            fclose($f);
        }, 'aims-vat-preview-'.$d['period'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function base(int $n): int
    {
        return in_array($n, [13, 15, 17, 19, 21, 23, 25, 27, 29, 36, 38, 40, 42, 44, 46, 48, 50, 52, 54, 56, 58, 60, 62, 64, 66], true) ? $n - 1 : $n;
    }
}
