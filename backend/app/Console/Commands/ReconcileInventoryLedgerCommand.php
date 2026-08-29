<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockMovementService;
use Illuminate\Console\Command;

class ReconcileInventoryLedgerCommand extends Command
{
    protected $signature = 'inventory:reconcile
        {--company= : Limit the audit to one company ID}
        {--repair : Add explicit opening/reconciliation ledger entries without changing product balances}';

    protected $description = 'Audit product balances against the immutable stock movement ledger';

    public function handle(StockMovementService $stockMovements): int
    {
        $companyId = $this->option('company');
        if ($companyId !== null && (! ctype_digit((string) $companyId) || (int) $companyId < 1)) {
            $this->error('The --company option must be a positive company ID.');

            return self::INVALID;
        }

        $products = Product::withoutGlobalScopes()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', (int) $companyId))
            ->orderBy('company_id')->orderBy('id')->get();

        $rows = [];
        $repairs = 0;
        foreach ($products as $product) {
            $movements = StockMovement::withoutGlobalScopes()
                ->where('company_id', $product->company_id)
                ->where('product_id', $product->id)
                ->orderByRaw('COALESCE(occurred_at, created_at) ASC')
                ->orderBy('id')
                ->get();

            $current = round((float) $product->quantity, 3);
            $first = $movements->first();
            $last = $movements->last();
            $missingOpening = $movements->isEmpty()
                ? abs($current) >= 0.0005
                : abs((float) $first->quantity_before) >= 0.0005
                    && ! $movements->contains('movement_code', 'opening_balance');
            $ledgerBalance = $last ? round((float) $last->quantity_after, 3) : 0.0;
            $balanceDrift = abs($ledgerBalance - $current) >= 0.0005;
            $continuityGaps = 0;
            $previous = null;
            foreach ($movements as $movement) {
                if ($previous && abs((float) $previous->quantity_after - (float) $movement->quantity_before) >= 0.0005) {
                    $continuityGaps++;
                }
                $previous = $movement;
            }

            if (! $missingOpening && ! $balanceDrift && $continuityGaps === 0) {
                continue;
            }

            $rows[] = [
                $product->company_id,
                $product->id,
                $product->sku,
                number_format($current, 3, '.', ''),
                number_format($ledgerBalance, 3, '.', ''),
                $missingOpening ? 'yes' : 'no',
                $continuityGaps,
            ];

            if (! $this->option('repair')) {
                continue;
            }

            if ($missingOpening) {
                $openingBalance = $first ? round((float) $first->quantity_before, 3) : $current;
                if (abs($openingBalance) >= 0.0005) {
                    $occurredAt = $first
                        ? ($first->occurred_at ?? $first->created_at)->copy()->subSecond()
                        : now();
                    $key = 'reconcile-opening-'.hash('sha256', "{$product->company_id}:{$product->id}:{$openingBalance}");
                    $movement = $stockMovements->recordReconciliationSnapshot(
                        $product,
                        0,
                        $openingBalance,
                        'opening_balance',
                        'Opening balance reconstructed from the earliest verified stock record',
                        $key,
                        $occurredAt,
                    );
                    if ($movement->wasRecentlyCreated) {
                        $repairs++;
                    }
                }
            }

            if ($balanceDrift && $last) {
                $key = 'reconcile-balance-'.hash('sha256', "{$product->company_id}:{$product->id}:{$last->id}:{$current}");
                $movement = $stockMovements->recordReconciliationSnapshot(
                    $product,
                    $ledgerBalance,
                    $current,
                    'reconciliation_adjustment',
                    'Ledger aligned to the preserved product balance by inventory reconciliation',
                    $key,
                );
                if ($movement->wasRecentlyCreated) {
                    $repairs++;
                }
            }
        }

        if ($rows === []) {
            $this->info("Inventory ledger is consistent for {$products->count()} products.");

            return self::SUCCESS;
        }

        $this->table(
            ['Company', 'Product', 'SKU', 'Product balance', 'Ledger balance', 'Missing opening', 'Continuity gaps'],
            $rows,
        );
        $this->warn(count($rows).' product(s) require inventory-ledger attention.');
        if ($this->option('repair')) {
            $this->info("{$repairs} explicit ledger repair movement(s) were added; product quantities were not changed.");
            if (collect($rows)->sum(fn ($row) => (int) $row[6]) > 0) {
                $this->warn('Historical continuity gaps remain listed for manual investigation; the command never fabricates intermediate transactions.');
            }
        } else {
            $this->line('Run again with --repair to add safe opening/current-balance ledger entries. Existing product balances and movements remain unchanged.');
        }

        return self::SUCCESS;
    }
}
