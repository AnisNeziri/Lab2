<?php

namespace App\Services;

use App\Models\Category;
use App\Models\DailySale;
use App\Models\LandedCostAccountingEntry;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use Carbon\CarbonImmutable;

class DashboardService
{
    public function __construct(
        private RedisStoreService $redisStore
    ) {}

    public function getMetrics(int $companyId): array
    {
        $metrics = $this->buildMetrics();

        $this->redisStore->setDashboardStats($companyId, [
            'total_products' => $metrics['total_products'],
            'total_categories' => $metrics['total_categories'],
            'total_suppliers' => $metrics['total_suppliers'],
            'total_units' => $metrics['total_units'],
            'total_value' => $metrics['total_value'],
            'inventory_value' => $metrics['inventory_value'],
            'low_stock_count' => $metrics['low_stock_count'],
            'out_of_stock_count' => $metrics['out_of_stock_count'],
            'stock_turnover' => $metrics['stock_turnover'],
        ]);

        $lowStockIds = collect($metrics['low_stock_products'])->pluck('id')->toArray();
        $this->redisStore->setLowStockAlerts($companyId, $lowStockIds);

        return $metrics;
    }

    /**
     * Return recorded daily-sales revenue and cost snapshots for a
     * calendar-aligned period.
     *
     * Weeks always run Monday-Sunday, months run from the first through the
     * last calendar day, and years run January 1 through December 31.
     */
    public function getSalesAnalytics(int $companyId, string $period = 'week', ?string $date = null): array
    {
        $anchor = $date
            ? CarbonImmutable::parse($date, config('app.timezone', 'UTC'))
            : CarbonImmutable::now(config('app.timezone', 'UTC'));

        [$start, $end, $bucketFormat] = match ($period) {
            'month' => [$anchor->startOfMonth(), $anchor->endOfMonth(), 'day'],
            'year' => [$anchor->startOfYear(), $anchor->endOfYear(), 'month'],
            default => [$anchor->startOfWeek(CarbonImmutable::MONDAY), $anchor->endOfWeek(CarbonImmutable::SUNDAY), 'day'],
        };

        $sales = DailySale::query()
            ->with(['items' => fn ($query) => $query->select([
                'id', 'daily_sale_id', 'line_total', 'cost_total', 'gross_profit',
            ])])
            ->where('company_id', $companyId)
            // Some SQLite desktop databases persist DATE casts with a
            // midnight component, so compare the date portion explicitly.
            ->whereDate('sale_date', '>=', $start->toDateString())
            ->whereDate('sale_date', '<=', $end->toDateString())
            ->get(['id', 'sale_date', 'status', 'total_amount', 'total_quantity']);

        $series = [];
        if ($bucketFormat === 'month') {
            $cursor = $start->startOfMonth();
            while ($cursor->lessThanOrEqualTo($end)) {
                $key = $cursor->format('Y-m');
                $series[$key] = [
                    'date' => $key,
                    'label' => $cursor->format('M'),
                    'sales' => 0.0,
                    'cost' => 0.0,
                    'profit' => 0.0,
                    'costed_revenue' => 0.0,
                    'uncosted_revenue' => 0.0,
                    'landed_cost_cogs_adjustment' => 0.0,
                    'quantity' => 0.0,
                    'transactions' => 0,
                ];
                $cursor = $cursor->addMonth();
            }
        } else {
            $cursor = $start->startOfDay();
            while ($cursor->lessThanOrEqualTo($end)) {
                $key = $cursor->toDateString();
                $series[$key] = [
                    'date' => $key,
                    'label' => $cursor->format('D d'),
                    'sales' => 0.0,
                    'cost' => 0.0,
                    'profit' => 0.0,
                    'costed_revenue' => 0.0,
                    'uncosted_revenue' => 0.0,
                    'landed_cost_cogs_adjustment' => 0.0,
                    'quantity' => 0.0,
                    'transactions' => 0,
                ];
                $cursor = $cursor->addDay();
            }
        }

        foreach ($sales as $sale) {
            $saleDate = CarbonImmutable::parse($sale->sale_date, $start->getTimezone());
            $key = $bucketFormat === 'month' ? $saleDate->format('Y-m') : $saleDate->toDateString();
            if (! isset($series[$key])) {
                continue;
            }
            $series[$key]['sales'] += (float) $sale->total_amount;
            $costedItems = $sale->items->whereNotNull('cost_total');
            $costedRevenue = (float) $costedItems->sum('line_total');
            $cost = (float) $costedItems->sum('cost_total');
            $recordedItemRevenue = (float) $sale->items->sum('line_total');
            $explicitUncostedRevenue = (float) $sale->items
                ->whereNull('cost_total')
                ->sum('line_total');
            $unallocatedRevenue = max(0, (float) $sale->total_amount - $recordedItemRevenue);

            $series[$key]['cost'] += $cost;
            $series[$key]['profit'] += $costedRevenue - $cost;
            $series[$key]['costed_revenue'] += $costedRevenue;
            $series[$key]['uncosted_revenue'] += $explicitUncostedRevenue + $unallocatedRevenue;
            $series[$key]['quantity'] += (float) $sale->total_quantity;
            $series[$key]['transactions']++;
        }

        // A supplier may invoice freight/customs after some receipt stock has
        // already sold. The auditable posting split records that portion as a
        // COGS adjustment on its posting date instead of silently adding it to
        // inventory that no longer exists.
        $landedCostAdjustments = LandedCostAccountingEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('posted_at', '>=', $start->toDateString())
            ->whereDate('posted_at', '<=', $end->toDateString())
            ->get(['posted_at', 'cogs_adjustment_amount']);
        foreach ($landedCostAdjustments as $adjustment) {
            $postedAt = CarbonImmutable::parse($adjustment->posted_at, $start->getTimezone());
            $key = $bucketFormat === 'month' ? $postedAt->format('Y-m') : $postedAt->toDateString();
            if (! isset($series[$key])) {
                continue;
            }
            $amount = (float) $adjustment->cogs_adjustment_amount;
            $series[$key]['cost'] += $amount;
            $series[$key]['profit'] -= $amount;
            $series[$key]['landed_cost_cogs_adjustment'] += $amount;
        }

        [$previousStart, $previousEnd] = match ($period) {
            'month' => [$start->subMonth()->startOfMonth(), $start->subDay()],
            'year' => [$start->subYear()->startOfYear(), $start->subDay()],
            default => [$start->subWeek(), $end->subWeek()],
        };

        $previousSales = DailySale::query()
            ->with(['items' => fn ($query) => $query->select([
                'id', 'daily_sale_id', 'line_total', 'cost_total', 'gross_profit',
            ])])
            ->where('company_id', $companyId)
            ->whereDate('sale_date', '>=', $previousStart->toDateString())
            ->whereDate('sale_date', '<=', $previousEnd->toDateString())
            ->get(['id', 'total_amount']);
        $previousTotal = (float) $previousSales->sum('total_amount');
        $previousProfit = (float) $previousSales->sum(
            fn (DailySale $sale) => $sale->items
                ->whereNotNull('cost_total')
                ->sum(fn ($item) => (float) $item->line_total - (float) $item->cost_total)
        );
        $previousLandedCostCogs = (float) LandedCostAccountingEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('posted_at', '>=', $previousStart->toDateString())
            ->whereDate('posted_at', '<=', $previousEnd->toDateString())
            ->sum('cogs_adjustment_amount');
        $previousProfit -= $previousLandedCostCogs;
        $totalSales = array_sum(array_column($series, 'sales'));
        $totalCost = array_sum(array_column($series, 'cost'));
        $grossProfit = array_sum(array_column($series, 'profit'));
        $landedCostCogs = array_sum(array_column($series, 'landed_cost_cogs_adjustment'));
        $costedRevenue = array_sum(array_column($series, 'costed_revenue'));
        $uncostedRevenue = array_sum(array_column($series, 'uncosted_revenue'));
        $totalQuantity = array_sum(array_column($series, 'quantity'));
        $transactionCount = array_sum(array_column($series, 'transactions'));

        return [
            'period' => in_array($period, ['week', 'month', 'year'], true) ? $period : 'week',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total_sales' => round($totalSales, 2),
            'total_cost' => round($totalCost, 2),
            'landed_cost_cogs_adjustment' => round($landedCostCogs, 2),
            'gross_profit' => round($grossProfit, 2),
            'gross_margin_percent' => $costedRevenue > 0 ? round(($grossProfit / $costedRevenue) * 100, 1) : null,
            'costed_revenue' => round($costedRevenue, 2),
            'uncosted_revenue' => round($uncostedRevenue, 2),
            'cost_coverage_percent' => $totalSales > 0
                ? round(min(100, ($costedRevenue / $totalSales) * 100), 1)
                : null,
            'margin_is_complete' => $uncostedRevenue <= 0.005,
            'total_quantity' => round($totalQuantity, 3),
            'transaction_count' => (int) $transactionCount,
            'average_sale' => $transactionCount > 0 ? round($totalSales / $transactionCount, 2) : 0.0,
            'finalized_transaction_count' => (int) $sales->where('status', 'finalized')->count(),
            'draft_transaction_count' => (int) $sales->where('status', 'draft')->count(),
            'previous_total_sales' => round($previousTotal, 2),
            'previous_gross_profit' => round($previousProfit, 2),
            'change_percent' => $previousTotal > 0 ? round((($totalSales - $previousTotal) / $previousTotal) * 100, 1) : null,
            'profit_change_percent' => $previousProfit != 0.0
                ? round((($grossProfit - $previousProfit) / abs($previousProfit)) * 100, 1)
                : null,
            'series' => array_values(array_map(static function (array $item): array {
                $item['sales'] = round($item['sales'], 2);
                $item['cost'] = round($item['cost'], 2);
                $item['profit'] = round($item['profit'], 2);
                $item['costed_revenue'] = round($item['costed_revenue'], 2);
                $item['uncosted_revenue'] = round($item['uncosted_revenue'], 2);
                $item['landed_cost_cogs_adjustment'] = round($item['landed_cost_cogs_adjustment'], 2);
                $item['margin_percent'] = $item['costed_revenue'] > 0
                    ? round(($item['profit'] / $item['costed_revenue']) * 100, 1)
                    : null;
                $item['quantity'] = round($item['quantity'], 3);

                return $item;
            }, $series)),
        ];
    }

    private function buildMetrics(): array
    {
        $products = Product::with('category')->get();

        $totalUnits = $products->sum('quantity');
        $totalValue = $products->sum(fn (Product $p) => $p->quantity * $p->price);
        $inventoryValue = $products->sum(fn (Product $p) => $p->quantity * ($p->purchase_price ?? $p->price)
        );
        $expectedNetProfit = $products->sum(fn (Product $p) => $p->quantity * (($p->selling_price ?? $p->price) - ($p->purchase_price ?? $p->price))
        );

        $lowStockProducts = $products->filter(fn (Product $p) => $p->quantity <= $p->min_quantity)->sortBy('quantity')->values();
        $outOfStockProducts = $products->filter(fn (Product $p) => $p->quantity <= 0)->sortBy('name')->values();

        $recentMovements = StockMovement::with('product.category')->latest()->limit(50)->get();

        $categoryValues = $products->groupBy(fn (Product $p) => $p->category->name ?? 'Uncategorized')
            ->map(fn ($group, $name) => [
                'name' => $name,
                'value' => round($group->sum(fn ($p) => $p->quantity * $p->price), 2),
            ])->values();

        $movementsOverTime = StockMovement::selectRaw('DATE(created_at) as date, type, SUM(quantity) as total_qty')
            ->groupBy('date', 'type')
            ->orderBy('date')
            ->get()
            ->groupBy('date')
            ->map(function ($items, $date) {
                $in = 0;
                $out = 0;
                foreach ($items as $item) {
                    $item->type === 'in' ? ($in += $item->total_qty) : ($out += $item->total_qty);
                }

                return ['date' => date('d M', strtotime($date)), 'in' => (int) $in, 'out' => (int) $out];
            })->values();

        $totalStockOut = (int) StockMovement::where('type', 'out')->sum('quantity');
        $stockTurnover = $totalUnits > 0 ? round($totalStockOut / $totalUnits, 2) : 0;

        return [
            'total_products' => $products->count(),
            'total_categories' => Category::count(),
            'total_suppliers' => Supplier::count(),
            'total_units' => $totalUnits,
            'total_value' => round($totalValue, 2),
            'inventory_value' => round($inventoryValue, 2),
            'expected_net_profit' => round($expectedNetProfit, 2),
            'stock_turnover' => $stockTurnover,
            'low_stock_count' => $lowStockProducts->count(),
            'low_stock_products' => $lowStockProducts,
            'out_of_stock_count' => $outOfStockProducts->count(),
            'out_of_stock_products' => $outOfStockProducts,
            'recent_movements' => $recentMovements,
            'category_values' => $categoryValues,
            'movements_over_time' => $movementsOverTime,
        ];
    }
}
