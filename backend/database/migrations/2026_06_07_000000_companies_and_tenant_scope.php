<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('address');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->boolean('must_change_password')->default(false)->after('role');
            $table->boolean('temporary_password_consumed')->default(false)->after('must_change_password');
        });

        $tenantTables = ['categories', 'suppliers', 'products', 'stock_movements', 'invoices', 'activity_logs'];

        foreach ($tenantTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
        }

        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Enterprise Demo Co.',
            'address' => '123 Enterprise Blvd, Business City, BC 10001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->update(['company_id' => $companyId]);

        foreach ($tenantTables as $tableName) {
            DB::table($tableName)->update(['company_id' => $companyId]);
        }
    }

    public function down(): void
    {
        $tenantTables = ['activity_logs', 'invoices', 'stock_movements', 'products', 'suppliers', 'categories'];

        foreach ($tenantTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('company_id');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['must_change_password', 'temporary_password_consumed']);
        });

        Schema::dropIfExists('companies');
    }
};
