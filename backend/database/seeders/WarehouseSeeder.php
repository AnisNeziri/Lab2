<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('name', 'Enterprise Demo Co.')->first();

        if (! $company) {
            return;
        }

        $warehouse = Warehouse::updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Main Warehouse'],
            [
                'code' => 'WH-MAIN',
                'address' => '123 Enterprise Blvd',
                'is_active' => true,
                'length_m' => 80,
                'width_m' => 90,
                'height_m' => 8,
                'floor_count' => 1,
            ]
        );

        Product::where('company_id', $company->id)->each(function (Product $product) use ($company, $warehouse) {
            WarehouseStock::updateOrCreate(
                ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
                [
                    'company_id' => $company->id,
                    'quantity' => $product->quantity,
                    'available_quantity' => $product->quantity,
                    'reserved_quantity' => 0,
                    'damaged_quantity' => 0,
                    'quarantine_quantity' => 0,
                    'blocked_quantity' => 0,
                ]
            );
        });
    }
}
