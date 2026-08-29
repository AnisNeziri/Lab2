<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'heading')) {
                $table->decimal('heading', 6, 2)->nullable()->after('course');
            }
            if (! Schema::hasColumn('shipments', 'navigation_status')) {
                $table->unsignedTinyInteger('navigation_status')->nullable()->after('heading');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('shipments', 'navigation_status')) {
                $columns[] = 'navigation_status';
            }
            if (Schema::hasColumn('shipments', 'heading')) {
                $columns[] = 'heading';
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
