<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $companyId = Company::firstOrFail()->id;

        $categories = [
            'Electronics',
            'Office Supplies',
            'Furniture',
            'Clothing',
            'Hardware',
            'Other',
        ];

        foreach ($categories as $name) {
            Category::firstOrCreate([
                'company_id' => $companyId,
                'name' => $name,
            ]);
        }
    }
}
