<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Validation\ValidationException;

class UnitConversionService
{
    public const MODES = ['fixed'];

    public function resolve(
        Product $product,
        float $quantity,
        ?string $unit = null,
        ?float $actualBaseQuantity = null,
        string $purpose = 'purchase',
    ): array {
        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Quantity must be greater than zero.']]);
        }

        $baseUnit = $this->cleanUnit($product->unit ?: 'pcs');
        $selectedUnit = $this->cleanUnit($unit ?: $baseUnit);

        if ($this->sameUnit($selectedUnit, $baseUnit)) {
            $this->assertPrecision($quantity, $baseUnit, 'quantity');

            return [
                'unit' => $selectedUnit,
                'inventory_unit' => $baseUnit,
                'conversion_mode' => 'none',
                'conversion_factor' => null,
                'base_quantity' => $quantity,
            ];
        }

        $product->loadMissing('units');
        /** @var ProductUnit|null $definition */
        $definition = $product->units->first(fn (ProductUnit $candidate) => $candidate->is_active
            && $this->sameUnit($candidate->code, $selectedUnit));

        if (! $definition) {
            throw ValidationException::withMessages([
                'unit' => ["{$selectedUnit} is not configured as a {$purpose} unit for {$product->name}."],
            ]);
        }

        $this->assertPrecision($quantity, $selectedUnit, 'quantity');
        $factor = round((float) $definition->factor_to_base, 6);
        if ($definition->conversion_mode !== 'fixed' || $factor <= 0) {
            throw ValidationException::withMessages([
                'unit' => ['The selected pack is no longer available. Configure a fixed quantity per pack on the product.'],
            ]);
        }
        $baseQuantity = round($quantity * $factor, 3);

        $this->assertPrecision($baseQuantity, $baseUnit, 'actual_base_quantity');

        return [
            'unit' => $definition->code,
            'inventory_unit' => $baseUnit,
            'conversion_mode' => 'fixed',
            'conversion_factor' => $factor,
            'base_quantity' => $baseQuantity,
        ];
    }

    public function defaultUnit(Product $product, string $purpose): string
    {
        return $product->unit ?: 'pcs';
    }

    public function describeOrderUnit(Product $product, float $quantity, ?string $unit = null, string $purpose = 'purchase'): array
    {
        $baseUnit = $this->cleanUnit($product->unit ?: 'pcs');
        $selectedUnit = $this->cleanUnit($unit ?: $this->defaultUnit($product, $purpose));
        if ($this->sameUnit($selectedUnit, $baseUnit)) {
            $this->assertPrecision($quantity, $baseUnit);

            return ['unit' => $baseUnit, 'inventory_unit' => $baseUnit, 'conversion_mode' => 'none', 'conversion_factor' => null, 'base_quantity' => round($quantity, 3)];
        }

        $product->loadMissing('units');
        $definition = $product->units->first(fn (ProductUnit $candidate) => $candidate->is_active
            && $this->sameUnit($candidate->code, $selectedUnit));
        if (! $definition) {
            throw ValidationException::withMessages(['unit' => ["{$selectedUnit} is not configured as a {$purpose} unit for {$product->name}."]]);
        }
        $this->assertPrecision($quantity, $selectedUnit);
        $factor = $definition->conversion_mode === 'fixed' ? round((float) $definition->factor_to_base, 6) : 0;
        if ($factor <= 0) {
            throw ValidationException::withMessages([
                'unit' => ['The selected pack is no longer available. Configure a fixed quantity per pack on the product.'],
            ]);
        }

        return [
            'unit' => $definition->code,
            'inventory_unit' => $baseUnit,
            'conversion_mode' => 'fixed',
            'conversion_factor' => $factor,
            'base_quantity' => $factor ? round($quantity * $factor, 3) : null,
        ];
    }

    public function resolveSnapshot(string $inventoryUnit, string $orderedUnit, string $mode, ?float $factor, float $quantity, ?float $actualBaseQuantity): float
    {
        $this->assertPrecision($quantity, $orderedUnit);
        if ($mode === 'none') {
            return round($quantity, 3);
        }
        if ($mode === 'fixed') {
            if ((float) $factor <= 0) {
                throw ValidationException::withMessages(['items' => ['A fixed unit conversion is missing its factor.']]);
            }

            return round($quantity * (float) $factor, 3);
        }
        $base = round((float) $actualBaseQuantity, 3);
        if ($base <= 0) {
            throw ValidationException::withMessages(['actual_base_quantity' => ["Enter the measured quantity in {$inventoryUnit}."]]);
        }
        $this->assertPrecision($base, $inventoryUnit, 'actual_base_quantity');

        return $base;
    }

    public function assertPrecision(float $quantity, ?string $unit, string $field = 'quantity'): void
    {
        if ($this->allowsDecimals($unit) || abs($quantity - round($quantity)) < 0.0005) {
            return;
        }

        throw ValidationException::withMessages([
            $field => ["{$unit} quantities must be whole numbers. Use the base measured unit for decimals."],
        ]);
    }

    public function allowsDecimals(?string $unit): bool
    {
        return in_array($this->normalize($unit), [
            'm', 'meter', 'meters', 'metre', 'metër', 'metri', 'metra',
        ], true);
    }

    public function cleanUnit(?string $unit): string
    {
        return trim((string) $unit) ?: 'pcs';
    }

    private function sameUnit(?string $left, ?string $right): bool
    {
        return $this->normalize($left) === $this->normalize($right);
    }

    private function normalize(?string $unit): string
    {
        return mb_strtolower(trim((string) $unit));
    }
}
