<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tracking\GlobalVesselService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalMapController extends Controller
{
    public function __construct(private readonly GlobalVesselService $vessels) {}

    public function vessels(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin' => ['nullable', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $filters = array_filter([
            'origin' => $validated['origin'] ?? null,
            'destination' => $validated['destination'] ?? null,
            'search' => $validated['search'] ?? null,
        ]);

        return response()->json([
            'vessels' => $this->vessels->vessels($filters),
            'metrics' => $this->vessels->metrics($filters),
        ]);
    }

    public function show(string $vesselId): JsonResponse
    {
        $vessel = $this->vessels->vessel($vesselId);

        if (! $vessel) {
            return response()->json(['message' => 'Vessel not found.'], 404);
        }

        return response()->json($vessel);
    }

    public function metrics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin' => ['nullable', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->vessels->metrics(array_filter($validated)));
    }

    public function ports(): JsonResponse
    {
        return response()->json($this->vessels->ports());
    }

}
