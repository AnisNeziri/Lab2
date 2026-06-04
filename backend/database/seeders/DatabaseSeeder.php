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
        \App\Models\User::create([
            'name' => 'Enterprise Admin',
            'email' => 'admin@enterprise.com',
            'password' => bcrypt('password'),
        ]);

        $this->call(CategorySeeder::class);
        $this->call(ProductSeeder::class);
        $this->call(InvoiceSeeder::class);
    }
}
