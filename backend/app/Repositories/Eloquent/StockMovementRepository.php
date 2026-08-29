<?php

namespace App\Repositories\Eloquent;

use App\Models\StockMovement;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class StockMovementRepository implements StockMovementRepositoryInterface
{
    public function list(array $filters, int $limit = 50): Collection
    {
        $query = StockMovement::with(['product.category', 'actor:id,name', 'warehouse:id,name,code', 'location:id,name,code,path'])
            ->orderByRaw('COALESCE(occurred_at, created_at) DESC')
            ->orderByDesc('id');

        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['movement_code'])) {
            $query->where('movement_code', $filters['movement_code']);
        }

        if (! empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }
        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }
        if (! empty($filters['stock_state'])) {
            $query->where('stock_state', $filters['stock_state']);
        }

        return $query->limit($limit)->get();
    }

    public function exportList(array $filters): Collection
    {
        $query = StockMovement::with(['product', 'actor:id,name', 'warehouse:id,name,code', 'location:id,name,code,path'])
            ->orderByRaw('COALESCE(occurred_at, created_at) DESC')
            ->orderByDesc('id');

        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['movement_code'])) {
            $query->where('movement_code', $filters['movement_code']);
        }

        if (! empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }
        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }
        if (! empty($filters['stock_state'])) {
            $query->where('stock_state', $filters['stock_state']);
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
            'total_stock_in' => round((float) (clone $query)->where('type', 'in')->sum('quantity'), 3),
            'total_stock_out' => round((float) (clone $query)->where('type', 'out')->sum('quantity'), 3),
            'movement_count' => (int) (clone $query)->count(),
        ];
    }
}
