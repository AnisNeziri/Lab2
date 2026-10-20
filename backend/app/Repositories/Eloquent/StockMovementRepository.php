<?php

namespace App\Repositories\Eloquent;

use App\Models\StockMovement;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

class StockMovementRepository implements StockMovementRepositoryInterface
{
    public function list(array $filters, int $limit = 50): Collection|LengthAwarePaginator
    {
        $query = StockMovement::with(['product' => fn ($query) => $query->withStockTotals()->with('category'), 'actor:id,name', 'warehouse:id,name,code', 'location:id,name,code,path'])
            ->orderByDesc('effective_occurred_at')
            ->orderByDesc('id');

        if (! empty($filters['from'])) $query->where('effective_occurred_at', '>=', $filters['from'].' 00:00:00');
        if (! empty($filters['to'])) $query->where('effective_occurred_at', '<=', $filters['to'].' 23:59:59');
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

        if (isset($filters['page'])) {
            return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 50))), ['*'], 'page', $filters['page']);
        }
        return $query->limit($limit)->get();
    }

    public function exportList(array $filters): LazyCollection
    {
        $query = StockMovement::with(['product' => fn ($query) => $query->withStockTotals(), 'actor:id,name', 'warehouse:id,name,code', 'location:id,name,code,path'])
            ->orderByDesc('effective_occurred_at')
            ->orderByDesc('id');

        if (! empty($filters['from'])) $query->where('effective_occurred_at', '>=', $filters['from'].' 00:00:00');
        if (! empty($filters['to'])) $query->where('effective_occurred_at', '<=', $filters['to'].' 23:59:59');
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

        // Stock history is append-only. Pin the upper ID so writes during export
        // cannot shift the offset pages. Eager relations are loaded per chunk.
        $upperId = (clone $query)->reorder()->max('id');
        $query->where('id', '<=', $upperId ?? 0);
        abort_if((clone $query)->reorder()->count() > 250000, 422,
            'This export exceeds 250,000 movements. Select a product, warehouse or date range.');
        return $query->lazy(500);
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

        $totals = $query->selectRaw("COUNT(*) AS movement_count, COALESCE(SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END), 0) AS stock_in, COALESCE(SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END), 0) AS stock_out")->first();
        return [
            'total_stock_in' => round((float) $totals->stock_in, 3),
            'total_stock_out' => round((float) $totals->stock_out, 3),
            'movement_count' => (int) $totals->movement_count,
        ];
    }
}
