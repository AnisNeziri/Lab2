<?php

namespace App\Repositories\Eloquent;

use App\Models\StockMovement;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class StockMovementRepository implements StockMovementRepositoryInterface
{
    public function list(array $filters, int $limit = 50): Collection
    {
        $query = StockMovement::with('product.category')->latest();

        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->limit($limit)->get();
    }

    public function exportList(array $filters): Collection
    {
        $query = StockMovement::with('product')->latest();

        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->get();
    }

    public function create(array $data): StockMovement
    {
        return StockMovement::create($data);
    }

    public function stockSummaryForCompany(?int $companyId): array
    {
        $query = StockMovement::query();

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return [
            'total_stock_in' => (int) (clone $query)->where('type', 'in')->sum('quantity'),
            'total_stock_out' => (int) (clone $query)->where('type', 'out')->sum('quantity'),
            'movement_count' => (int) (clone $query)->count(),
        ];
    }
}
