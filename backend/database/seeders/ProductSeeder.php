<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'category' => 'Electronics',
                'name' => 'Wireless Mouse',
                'sku' => 'ELEC-001',
                'description' => 'Basic wireless mouse for office use',
                'quantity' => 25,
                'min_quantity' => 10,
                'price' => 19.99,
            ],
            [
                'category' => 'Electronics',
                'name' => 'USB-C Hub',
                'sku' => 'ELEC-002',
                'description' => '4-port USB hub',
                'quantity' => 8,
                'min_quantity' => 5,
                'price' => 34.50,
            ],
            [
                'category' => 'Office Supplies',
                'name' => 'A4 Paper Pack',
                'sku' => 'OFF-001',
                'description' => '500 sheets, 80gsm',
                'quantity' => 40,
                'min_quantity' => 15,
                'price' => 6.99,
            ],
            [
                'category' => 'Furniture',
                'name' => 'Office Chair',
                'sku' => 'FUR-001',
                'description' => 'Adjustable desk chair',
                'quantity' => 3,
                'min_quantity' => 2,
                'price' => 149.00,
            ],
            [
                'category' => 'Clothing',
                'name' => 'Staff Polo Shirt',
                'sku' => 'CLT-001',
                'description' => 'Medium size, navy blue',
                'quantity' => 12,
                'min_quantity' => 5,
                'price' => 24.00,
            ],
        ];

        foreach ($products as $item) {
            $category = Category::where('name', $item['category'])->first();

            if (! $category) {
                continue;
            }

            Product::firstOrCreate(
                ['sku' => $item['sku']],
                [
                    'category_id' => $category->id,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'min_quantity' => $item['min_quantity'],
                    'price' => $item['price'],
                ]
            );
        }
    }
}
