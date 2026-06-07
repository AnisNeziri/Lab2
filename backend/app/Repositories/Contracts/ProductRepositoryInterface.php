<?php

namespace App\Repositories\Contracts;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ProductRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function allWithRelations(): Collection;

    public function findBySku(string $sku): ?Product;

    public function findById(int $id): ?Product;

    public function create(array $data): Product;

    public function update(Product $product, array $data): Product;

    public function delete(Product $product): void;

    public function searchGlobal(string $term, int $limit = 20): Collection;

    public function byLocationCode(string $locationCode, int $companyId): Collection;
}
