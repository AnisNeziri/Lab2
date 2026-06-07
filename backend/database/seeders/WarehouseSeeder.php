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
            ]
        );

        Product::where('company_id', $company->id)->each(function (Product $product) use ($warehouse) {
            WarehouseStock::updateOrCreate(
                ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
                ['quantity' => $product->quantity]
            );
        });
    }
}
