<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouses', 'length_m')) {
                $table->decimal('length_m', 8, 2)->nullable()->after('address');
            }
            if (! Schema::hasColumn('warehouses', 'width_m')) {
                $table->decimal('width_m', 8, 2)->nullable()->after('length_m');
            }
            if (! Schema::hasColumn('warehouses', 'height_m')) {
                $table->decimal('height_m', 8, 2)->nullable()->after('width_m');
            }
        });

        if (! Schema::hasTable('warehouse_sections')) {
            Schema::create('warehouse_sections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->string('code', 20);
                $table->string('name');
                $table->string('color', 20)->default('#6366f1');
                $table->string('light_color', 20)->default('#818cf8');
                $table->decimal('pos_x', 8, 2)->default(0);
                $table->decimal('pos_z', 8, 2)->default(0);
                $table->decimal('width', 8, 2)->default(4);
                $table->decimal('depth', 8, 2)->default(4);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['warehouse_id', 'code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_sections');

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['length_m', 'width_m', 'height_m']);
        });
    }
};
