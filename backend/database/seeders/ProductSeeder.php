<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = Company::firstOrFail()->id;

        $products = [
            ['category' => 'Electronics', 'supplier' => 'TechSupply Co.',  'name' => 'Wireless Mouse',      'sku' => 'ELEC-001', 'location_code' => 'A1', 'quantity' => 45, 'min_quantity' => 10, 'high_stock_threshold' => 60,  'price' => 19.99, 'unit' => 'pcs'],
            ['category' => 'Electronics', 'supplier' => 'TechSupply Co.',  'name' => 'USB-C Hub 4-Port',    'sku' => 'ELEC-002', 'location_code' => 'A1', 'quantity' => 8,  'min_quantity' => 5,  'high_stock_threshold' => 15,  'price' => 34.50, 'unit' => 'pcs'],
            ['category' => 'Electronics', 'supplier' => 'TechSupply Co.',  'name' => 'Mechanical Keyboard', 'sku' => 'ELEC-003', 'location_code' => 'A2', 'quantity' => 30, 'min_quantity' => 8,  'high_stock_threshold' => 40,  'price' => 79.00, 'unit' => 'pcs'],
            ['category' => 'Electronics', 'supplier' => 'TechSupply Co.',  'name' => 'HDMI Cable 2m',       'sku' => 'ELEC-004', 'location_code' => 'A2', 'quantity' => 60, 'min_quantity' => 20, 'high_stock_threshold' => 80,  'price' =>  9.99, 'unit' => 'pcs'],
            ['category' => 'Electronics', 'supplier' => 'TechSupply Co.',  'name' => 'Webcam 1080p',        'sku' => 'ELEC-005', 'location_code' => 'A3', 'quantity' => 12, 'min_quantity' => 5,  'high_stock_threshold' => 20,  'price' => 55.00, 'unit' => 'pcs'],
            ['category' => 'Electronics', 'supplier' => 'TechSupply Co.',  'name' => 'USB Flash Drive 64GB','sku' => 'ELEC-006', 'location_code' => 'A3', 'quantity' =>  3, 'min_quantity' => 10, 'high_stock_threshold' => 50,  'price' => 12.50, 'unit' => 'pcs'],
            ['category' => 'Electronics', 'supplier' => 'TechSupply Co.',  'name' => 'Laptop Stand',        'sku' => 'ELEC-007', 'location_code' => 'A4', 'quantity' => 22, 'min_quantity' => 5,  'high_stock_threshold' => 30,  'price' => 29.99, 'unit' => 'pcs'],
            ['category' => 'Electronics', 'supplier' => 'TechSupply Co.',  'name' => 'Monitor 24"',         'sku' => 'ELEC-008', 'location_code' => 'A4', 'quantity' =>  0, 'min_quantity' => 2,  'high_stock_threshold' => 8,   'price' =>189.00, 'unit' => 'pcs'],

            ['category' => 'Hardware',    'supplier' => 'Global Furniture', 'name' => 'Steel Bolt Set M6',  'sku' => 'HW-001',   'location_code' => 'B1', 'quantity' => 200,'min_quantity' => 50, 'high_stock_threshold' => 300, 'price' =>  4.50, 'unit' => 'box'],
            ['category' => 'Hardware',    'supplier' => 'Global Furniture', 'name' => 'Drill Bit Set',      'sku' => 'HW-002',   'location_code' => 'B1', 'quantity' => 15, 'min_quantity' => 5,  'high_stock_threshold' => 25,  'price' => 18.00, 'unit' => 'set'],
            ['category' => 'Hardware',    'supplier' => 'Global Furniture', 'name' => 'Hex Wrench Set',     'sku' => 'HW-003',   'location_code' => 'B2', 'quantity' => 35, 'min_quantity' => 10, 'high_stock_threshold' => 50,  'price' => 11.99, 'unit' => 'set'],
            ['category' => 'Hardware',    'supplier' => 'Global Furniture', 'name' => 'Safety Gloves L',    'sku' => 'HW-004',   'location_code' => 'B2', 'quantity' =>  5, 'min_quantity' => 10, 'high_stock_threshold' => 40,  'price' =>  6.99, 'unit' => 'pair'],
            ['category' => 'Hardware',    'supplier' => 'Global Furniture', 'name' => 'Power Strip 6-Plug', 'sku' => 'HW-005',   'location_code' => 'B3', 'quantity' => 50, 'min_quantity' => 10, 'high_stock_threshold' => 60,  'price' => 22.00, 'unit' => 'pcs'],
            ['category' => 'Hardware',    'supplier' => 'Global Furniture', 'name' => 'Cable Ties 100pk',   'sku' => 'HW-006',   'location_code' => 'B3', 'quantity' => 80, 'min_quantity' => 20, 'high_stock_threshold' => 100, 'price' =>  3.50, 'unit' => 'pack'],
            ['category' => 'Hardware',    'supplier' => 'Global Furniture', 'name' => 'Tool Box Medium',    'sku' => 'HW-007',   'location_code' => 'B4', 'quantity' =>  7, 'min_quantity' => 3,  'high_stock_threshold' => 12,  'price' => 45.00, 'unit' => 'pcs'],
            ['category' => 'Hardware',    'supplier' => 'Global Furniture', 'name' => 'Measuring Tape 5m',  'sku' => 'HW-008',   'location_code' => 'B4', 'quantity' => 28, 'min_quantity' => 8,  'high_stock_threshold' => 40,  'price' =>  7.99, 'unit' => 'pcs'],

            ['category' => 'Office Supplies','supplier' => 'Office Depot KS','name' => 'A4 Paper Pack',     'sku' => 'OFF-001',  'location_code' => 'C1', 'quantity' => 90, 'min_quantity' => 20, 'high_stock_threshold' => 100, 'price' =>  6.99, 'unit' => 'pack'],
            ['category' => 'Office Supplies','supplier' => 'Office Depot KS','name' => 'Ballpoint Pen Box', 'sku' => 'OFF-002',  'location_code' => 'C1', 'quantity' => 60, 'min_quantity' => 15, 'high_stock_threshold' => 80,  'price' =>  8.50, 'unit' => 'box'],
            ['category' => 'Office Supplies','supplier' => 'Office Depot KS','name' => 'Sticky Notes 5pk',  'sku' => 'OFF-003',  'location_code' => 'C2', 'quantity' => 40, 'min_quantity' => 10, 'high_stock_threshold' => 60,  'price' =>  4.25, 'unit' => 'pack'],
            ['category' => 'Office Supplies','supplier' => 'Office Depot KS','name' => 'Stapler Heavy Duty', 'sku' => 'OFF-004', 'location_code' => 'C2', 'quantity' =>  4, 'min_quantity' => 5,  'high_stock_threshold' => 15,  'price' => 14.00, 'unit' => 'pcs'],
            ['category' => 'Office Supplies','supplier' => 'Office Depot KS','name' => 'Whiteboard Markers', 'sku' => 'OFF-005', 'location_code' => 'C3', 'quantity' => 55, 'min_quantity' => 10, 'high_stock_threshold' => 70,  'price' =>  9.99, 'unit' => 'set'],
            ['category' => 'Office Supplies','supplier' => 'Office Depot KS','name' => 'Printer Toner HP',  'sku' => 'OFF-006',  'location_code' => 'C3', 'quantity' =>  2, 'min_quantity' => 3,  'high_stock_threshold' => 8,   'price' => 65.00, 'unit' => 'pcs'],
            ['category' => 'Office Supplies','supplier' => 'Office Depot KS','name' => 'Scissors Office',   'sku' => 'OFF-007',  'location_code' => 'C4', 'quantity' => 25, 'min_quantity' => 5,  'high_stock_threshold' => 35,  'price' =>  5.50, 'unit' => 'pcs'],
            ['category' => 'Office Supplies','supplier' => 'Office Depot KS','name' => 'Tape Dispenser',    'sku' => 'OFF-008',  'location_code' => 'C4', 'quantity' => 18, 'min_quantity' => 5,  'high_stock_threshold' => 25,  'price' =>  7.75, 'unit' => 'pcs'],

            ['category' => 'Clothing',    'supplier' => 'Office Depot KS', 'name' => 'Staff Polo Shirt M',  'sku' => 'CLT-001',  'location_code' => 'D1', 'quantity' => 12, 'min_quantity' => 5,  'high_stock_threshold' => 20,  'price' => 24.00, 'unit' => 'pcs'],
            ['category' => 'Clothing',    'supplier' => 'Office Depot KS', 'name' => 'Staff Polo Shirt L',  'sku' => 'CLT-002',  'location_code' => 'D1', 'quantity' =>  6, 'min_quantity' => 5,  'high_stock_threshold' => 20,  'price' => 24.00, 'unit' => 'pcs'],
            ['category' => 'Furniture',   'supplier' => 'Global Furniture','name' => 'Office Chair',        'sku' => 'FUR-001',  'location_code' => 'D2', 'quantity' =>  3, 'min_quantity' => 2,  'high_stock_threshold' => 6,   'price' =>149.00, 'unit' => 'pcs'],
            ['category' => 'Furniture',   'supplier' => 'Global Furniture','name' => 'Desk Lamp LED',       'sku' => 'FUR-002',  'location_code' => 'D2', 'quantity' => 20, 'min_quantity' => 5,  'high_stock_threshold' => 30,  'price' => 35.00, 'unit' => 'pcs'],
            ['category' => 'Electronics', 'supplier' => 'TechSupply Co.',  'name' => 'Extension Cord 3m',   'sku' => 'ELEC-009', 'location_code' => 'D3', 'quantity' => 33, 'min_quantity' => 10, 'high_stock_threshold' => 50,  'price' => 13.50, 'unit' => 'pcs'],
            ['category' => 'Office Supplies','supplier' => 'Office Depot KS','name' => 'Cardboard Box Lg', 'sku' => 'OFF-009',   'location_code' => 'D3', 'quantity' => 70, 'min_quantity' => 20, 'high_stock_threshold' => 100, 'price' =>  2.99, 'unit' => 'pcs'],
            ['category' => 'Hardware',    'supplier' => 'Global Furniture', 'name' => 'Padlock Heavy',      'sku' => 'HW-009',   'location_code' => 'D4', 'quantity' =>  9, 'min_quantity' => 5,  'high_stock_threshold' => 15,  'price' => 19.00, 'unit' => 'pcs'],
            ['category' => 'Hardware',    'supplier' => 'Global Furniture', 'name' => 'Shelving Bracket',   'sku' => 'HW-010',   'location_code' => 'D4', 'quantity' => 44, 'min_quantity' => 10, 'high_stock_threshold' => 60,  'price' =>  8.25, 'unit' => 'pcs'],
        ];

        foreach ($products as $item) {
            $category = Category::where('name', $item['category'])->first();
            $supplier = Supplier::where('name', $item['supplier'])->first();

            if (! $category) continue;

            Product::updateOrCreate(
                ['company_id' => $companyId, 'sku' => $item['sku']],
                [
                    'company_id'          => $companyId,
                    'category_id'         => $category->id,
                    'supplier_id'         => $supplier?->id,
                    'name'                => $item['name'],
                    'sku'                 => $item['sku'],
                    'location_code'       => $item['location_code'],
                    'description'         => $item['description'] ?? null,
                    'quantity'            => $item['quantity'],
                    'unit'                => $item['unit'] ?? 'pcs',
                    'min_quantity'        => $item['min_quantity'],
                    'high_stock_threshold'=> $item['high_stock_threshold'] ?? 0,
                    'price'               => $item['price'],
                ]
            );
        }
    }
}
