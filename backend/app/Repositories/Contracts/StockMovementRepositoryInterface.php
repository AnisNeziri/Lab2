<?php

namespace App\Repositories\Contracts;

use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

interface StockMovementRepositoryInterface
{
    public function list(array $filters, int $limit = 50): Collection|LengthAwarePaginator;

    public function exportList(array $filters): LazyCollection;

    public function create(array $data): StockMovement;

    public function stockSummaryForCompany(?int $companyId): array;
}
