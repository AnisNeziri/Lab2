<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EntityContextService;
use Illuminate\Http\JsonResponse;

class EntityContextController extends Controller
{
    public function __construct(private readonly EntityContextService $context) {}

    public function show(string $type, int $id): JsonResponse
    {
        return response()->json($this->context->get($type, $id));
    }
}
