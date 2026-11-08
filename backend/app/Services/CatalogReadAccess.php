<?php
namespace App\Services;

/** HTTP catalog visibility only; never mutates domain models or valuation inputs. */
final class CatalogReadAccess
{
    public function permissions(): array
    {
        return auth()->user()?->company_id ? app(PermissionService::class)->forRole(auth()->user()->role) : [];
    }

    public function inventory(array $permissions): bool
    {
        return (bool) array_intersect($permissions, ['inventory.view', 'products.manage']);
    }

    public function reference(array $permissions): bool
    {
        return $this->inventory($permissions) || (bool) array_intersect($permissions, ['fulfillment.view', 'daily_sales.manage', 'invoices.manage', 'procurement.view', 'purchase_orders.view']);
    }

    public function costs(array $permissions): bool
    {
        return (bool) array_intersect($permissions, ['products.manage', 'supplier_catalogue.view', 'finance.view', 'accounting.reports.view', 'analytics.finance']);
    }

    public function suppliers(array $permissions): bool
    {
        return (bool) array_intersect($permissions, ['suppliers.manage','supplier_catalogue.view','procurement.view','purchase_orders.view','finance.view']);
    }

    public function redact(array $data): array
    {
        // These fields represent inventory acquisition/valuation, never selling prices.
        $product=isset($data['sku']) || array_key_exists('weighted_average_cost',$data) || array_key_exists('inventory_value',$data);
        foreach (['purchase_price','weighted_average_cost','inventory_value','unit_cost','unit_cost_base','unit_cost_snapshot','cost_per_unit','base_unit_cost','cost_total','landed_unit_cost','supplier_catalogue','supplier_catalog'] as $key) unset($data[$key]);
        if($product)unset($data['supplier'],$data['supplier_id']);
        foreach ($data as $key => $value) if (is_array($value)) $data[$key] = $this->redact($value);
        return $data;
    }

    public function referenceProduct(array $product): array
    {
        return array_intersect_key($product, array_flip(['id','name','sku','barcode','unit','price','selling_price','vat_rate','tax_treatment','tax_legal_reference','default_tax_code_id','category_id','lifecycle_status','units']));
    }
}
