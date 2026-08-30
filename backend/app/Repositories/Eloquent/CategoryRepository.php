<?php

namespace App\Repositories\Eloquent;

use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CategoryRepository implements CategoryRepositoryInterface
{
    public function allWithProductCount(): Collection
    {
        return Category::withCount('products')->orderBy('name')->get();
    }

    public function create(array $data): Category
    {
        return Category::create($data);
    }

    public function update(Category $category, array $data): Category
    {
        $category->update($data);

        return $category->fresh();
    }

    public function delete(Category $category): void
    {
        if ($category->products()->exists()) {
            throw ValidationException::withMessages([
                'category' => ['A category used by products cannot be deleted. Reassign the products first.'],
            ]);
        }

        $category->delete();
    }

    public function findByName(string $name): ?Category
    {
        return Category::where('name', $name)->first();
    }
}
