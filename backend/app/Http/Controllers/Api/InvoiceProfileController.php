<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceProfileRequest;
use App\Services\InvoiceProfileService;
use Illuminate\Http\JsonResponse;

class InvoiceProfileController extends Controller
{
    public function __construct(private readonly InvoiceProfileService $profiles) {}

    public function show(): JsonResponse
    {
        return response()->json($this->profiles->getForCurrentCompany());
    }

    public function update(InvoiceProfileRequest $request): JsonResponse
    {
        return response()->json($this->profiles->updateForCurrentCompany($request->validated()));
    }
}
