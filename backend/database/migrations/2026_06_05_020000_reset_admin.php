<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Reset admin password and ensure role is set
        DB::table('users')
            ->where('email', 'admin@enterprise.com')
            ->update([
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to reverse
    }
};
