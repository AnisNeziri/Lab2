<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'position_updated_at')) {
                $table->timestamp('position_updated_at')->nullable()->after('current_lng');
            }
            if (! Schema::hasColumn('shipments', 'speed_knots')) {
                $table->decimal('speed_knots', 8, 2)->nullable()->after('position_updated_at');
            }
            if (! Schema::hasColumn('shipments', 'course')) {
                $table->decimal('course', 6, 2)->nullable()->after('speed_knots');
            }
        });
    }

    public function down(): void {}
};
