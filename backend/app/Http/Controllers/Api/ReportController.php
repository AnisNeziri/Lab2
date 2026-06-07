<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(
        private ReportService $reportService
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->reportService->generate());
    }
}
