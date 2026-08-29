<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['name' => 'Enterprise Demo Co.'],
            ['address' => '123 Enterprise Blvd, Business City, BC 10001']
        );

        User::updateOrCreate(
            ['email' => 'admin@enterprise.com'],
            [
                'company_id' => $company->id,
                'name' => 'Enterprise Admin',
                'password' => bcrypt('password'),
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_consumed' => false,
            ]
        );

        User::updateOrCreate(
            ['email' => 'manager@enterprise.com'],
            [
                'company_id' => $company->id,
                'name' => 'Enterprise Manager',
                'password' => bcrypt('password'),
                'role' => 'manager',
                'is_active' => true,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_consumed' => false,
            ]
        );

        User::updateOrCreate(
            ['email' => 'staff@enterprise.com'],
            [
                'company_id' => $company->id,
                'name' => 'Enterprise Staff',
                'password' => bcrypt('password'),
                'role' => 'staff',
                'is_active' => true,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_consumed' => false,
            ]
        );

        $this->call([
            RolePermissionSeeder::class,
            CategorySeeder::class,
            SupplierSeeder::class,
            ProductSeeder::class,
            InvoiceSeeder::class,
            CmsSeeder::class,
            WarehouseSeeder::class,
        ]);
    }
}
