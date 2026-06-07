<?php

namespace App\Repositories\Contracts;

use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;

interface StockMovementRepositoryInterface
{
    public function list(array $filters, int $limit = 50): Collection;

    public function exportList(array $filters): Collection;

    public function create(array $data): StockMovement;

    public function stockSummaryForCompany(?int $companyId): array;
}
