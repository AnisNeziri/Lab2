<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function __construct(
        private CategoryRepositoryInterface $categories
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->categories->allWithProductCount());
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->where('company_id', $companyId)],
        ]);

        $validated['company_id'] = $companyId;
        $category = $this->categories->create($validated);

        return response()->json($category, 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->where('company_id', $companyId)->ignore($category->id)],
        ]);

        $category = $this->categories->update($category, $validated);

        return response()->json($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a category that still has products assigned.',
            ], 422);
        }

        $this->categories->delete($category);

        return response()->json(null, 204);
    }
}
