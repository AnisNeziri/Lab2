<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemIntegrityService;
use Illuminate\Http\JsonResponse;

class SystemIntegrityController extends Controller
{
    public function __construct(private readonly SystemIntegrityService $integrity) {}

    public function show(): JsonResponse
    {
        return response()->json($this->integrity->snapshot());
    }
}
