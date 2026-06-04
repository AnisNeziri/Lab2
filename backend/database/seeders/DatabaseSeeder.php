<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Admin User - Full access
        \App\Models\User::updateOrCreate(
            ['email' => 'admin@enterprise.com'],
            [
                'name' => 'Enterprise Admin',
                'password' => bcrypt('password'),
                'role' => 'admin',
            ]
        );

        // Manager User - Can delete products, categories, suppliers
        \App\Models\User::updateOrCreate(
            ['email' => 'manager@enterprise.com'],
            [
                'name' => 'Enterprise Manager',
                'password' => bcrypt('password'),
                'role' => 'manager',
            ]
        );

        // Staff User - Read and edit only
        \App\Models\User::updateOrCreate(
            ['email' => 'staff@enterprise.com'],
            [
                'name' => 'Enterprise Staff',
                'password' => bcrypt('password'),
                'role' => 'staff',
            ]
        );

        $this->call([
            CategorySeeder::class,
            SupplierSeeder::class,
            ProductSeeder::class,
            InvoiceSeeder::class,
        ]);
    }
}
