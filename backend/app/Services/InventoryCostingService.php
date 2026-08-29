<?php

namespace App\Services;

use App\Models\Product;

class InventoryCostingService
{
    public function currentUnitCost(Product $product): ?float
    {
        if ($product->weighted_average_cost !== null) {
            return round((float) $product->weighted_average_cost, 6);
        }

        if ($product->purchase_price !== null) {
            return round((float) $product->purchase_price, 6);
        }

        return null;
    }

    /**
     * Calculate a perpetual weighted-average snapshot. The caller persists the
     * returned product values in the same transaction as the stock movement.
     */
    public function movementSnapshot(
        Product $product,
        string $type,
        float $quantity,
        float $quantityBefore,
        float $quantityAfter,
        bool $affectsCompanyQuantity,
        ?float $basePurchaseUnitCost = null,
        ?float $landedCostUnit = null,
        ?float $finalUnitCost = null,
    ): array {
        $averageBefore = $this->currentUnitCost($product);
        $valueBefore = $this->currentInventoryValue($product, $quantityBefore, $averageBefore);
        $basePurchaseUnitCost = $basePurchaseUnitCost === null ? null : round($basePurchaseUnitCost, 6);
        $landedCostUnit = $landedCostUnit === null ? null : round($landedCostUnit, 6);
        if ($finalUnitCost === null && ($basePurchaseUnitCost !== null || $landedCostUnit !== null)) {
            $finalUnitCost = round(($basePurchaseUnitCost ?? 0) + ($landedCostUnit ?? 0), 6);
        }
        $finalUnitCost = $finalUnitCost === null ? null : round($finalUnitCost, 6);

        $averageAfter = $averageBefore;
        $valueAfter = $valueBefore;
        $movementUnitCost = $averageBefore;

        if ($affectsCompanyQuantity && $type === 'in') {
            $movementUnitCost = $finalUnitCost ?? $averageBefore;
            if ($movementUnitCost !== null) {
                $priorValue = $valueBefore ?? round($quantityBefore * ($averageBefore ?? 0), 6);
                $valueAfter = round($priorValue + ($quantity * $movementUnitCost), 6);
                $averageAfter = $quantityAfter > 0
                    ? round($valueAfter / $quantityAfter, 6)
                    : $movementUnitCost;
            } else {
                $valueAfter = null;
                $averageAfter = null;
            }
        } elseif ($affectsCompanyQuantity && $type === 'out') {
            $movementUnitCost = $averageBefore;
            if ($movementUnitCost !== null) {
                $valueAfter = round(max(0, $quantityAfter) * $movementUnitCost, 6);
                $averageAfter = $movementUnitCost;
            } else {
                $valueAfter = null;
                $averageAfter = null;
            }
        }

        return [
            'base_purchase_unit_cost' => $basePurchaseUnitCost,
            'landed_cost_unit' => $landedCostUnit,
            'final_unit_cost' => $movementUnitCost,
            'cost_total' => $movementUnitCost === null ? null : round($quantity * $movementUnitCost, 6),
            'weighted_average_cost_before' => $averageBefore,
            'weighted_average_cost_after' => $averageAfter,
            'inventory_value_before' => $valueBefore,
            'inventory_value_after' => $valueAfter,
            'product_weighted_average_cost' => $averageAfter,
            'product_inventory_value' => $valueAfter,
        ];
    }

    /**
     * Apply a non-quantity landed-cost adjustment to the current valuation.
     * If no units remain, the receipt keeps the historical allocation but the
     * current inventory value is not increased.
     */
    public function applyLandedCostAdjustment(Product $product, float $amount): array
    {
        $quantity = round((float) $product->quantity, 3);
        $averageBefore = $this->currentUnitCost($product);
        $valueBefore = $this->currentInventoryValue($product, $quantity, $averageBefore);

        if ($quantity <= 0) {
            return [
                'weighted_average_cost_before' => $averageBefore,
                'weighted_average_cost_after' => $averageBefore,
                'inventory_value_before' => $valueBefore,
                'inventory_value_after' => $valueBefore,
                'amount_applied_to_current_inventory' => 0.0,
            ];
        }

        $newValue = round(($valueBefore ?? 0) + $amount, 6);
        $newAverage = round($newValue / $quantity, 6);
        $product->forceFill([
            'weighted_average_cost' => $newAverage,
            'inventory_value' => $newValue,
        ])->save();

        return [
            'weighted_average_cost_before' => $averageBefore,
            'weighted_average_cost_after' => $newAverage,
            'inventory_value_before' => $valueBefore,
            'inventory_value_after' => $newValue,
            'amount_applied_to_current_inventory' => round($amount, 6),
        ];
    }

    private function currentInventoryValue(Product $product, float $quantity, ?float $average): ?float
    {
        if ($product->inventory_value !== null) {
            return round((float) $product->inventory_value, 6);
        }

        return $average === null ? null : round($quantity * $average, 6);
    }
}
