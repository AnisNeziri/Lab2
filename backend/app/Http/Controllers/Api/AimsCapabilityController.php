<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AimsToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AimsCapabilityController extends Controller
{
    public function __construct(private readonly AimsToolRegistry $tools) {}
    public function index(): JsonResponse { return response()->json(['data' => $this->tools->catalog()]); }
    public function execute(Request $request, string $tool): JsonResponse
    {
        $data = $request->validate(['input' => ['sometimes', 'array']]);
        return response()->json($this->tools->execute($tool, $data['input'] ?? []));
    }
}
