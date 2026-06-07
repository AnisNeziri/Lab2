<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(private DataExportService $exports)
    {
    }

    public function show(Request $request, string $list): Response|StreamedResponse
    {
        return $this->exports->export($list, $request->query('format', 'csv'));
    }
}
