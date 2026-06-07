<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Services\CmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CmsController extends Controller
{
    public function __construct(
        private CmsService $cmsService
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->cmsService->listEditable());
    }

    public function published(): JsonResponse
    {
        return response()->json($this->cmsService->listPublished());
    }

    public function show(string $slug): JsonResponse
    {
        $page = $this->cmsService->findBySlug($slug);

        if (! $page) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        return response()->json($page);
    }

    public function update(Request $request, CmsPage $cmsPage): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'string'],
            'is_published' => ['boolean'],
        ]);

        $page = $this->cmsService->update($cmsPage, $validated);

        return response()->json($page);
    }
}
