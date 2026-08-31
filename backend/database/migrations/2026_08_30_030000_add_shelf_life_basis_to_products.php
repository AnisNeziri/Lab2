<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // Existing products default to the safer rule: a shelf life can
            // only be derived from a known manufacture date. Products that
            // intentionally start their shelf life when received opt in.
            $table->string('shelf_life_basis', 20)
                ->default('manufacture_date')
                ->after('default_shelf_life_days');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('shelf_life_basis');
        });
    }
};
