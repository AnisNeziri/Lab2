<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            [
                'name' => 'TechSupply Co.',
                'phone' => '+383 44 123 456',
                'email' => 'orders@techsupply.example',
                'address' => 'Rr. Bill Clinton, Prishtine',
            ],
            [
                'name' => 'Office Depot KS',
                'phone' => '+383 38 987 654',
                'email' => 'sales@officedepot.example',
                'address' => 'Rr. Nena Tereze, Prizren',
            ],
            [
                'name' => 'Global Furniture',
                'phone' => null,
                'email' => 'info@globalfurniture.example',
                'address' => 'Industrial Zone, Ferizaj',
            ],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::firstOrCreate(
                ['email' => $supplier['email']],
                $supplier
            );
        }
    }
}
