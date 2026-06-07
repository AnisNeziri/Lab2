<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataImportService;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function __construct(
        private ImportService $importService,
        private DataImportService $dataImportService
    ) {}

    public function products(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $log = $this->importService->importProducts($request->file('file'));

        return response()->json($log, 201);
    }

    public function import(Request $request, string $list): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,json,xlsx', 'max:5120'],
        ]);

        $log = $this->dataImportService->import($list, $request->file('file'));

        return response()->json($log, 201);
    }
}
