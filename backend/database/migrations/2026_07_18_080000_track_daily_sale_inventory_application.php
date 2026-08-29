<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_sales', function (Blueprint $table) {
            $table->timestamp('inventory_applied_at')->nullable()->after('finalized_at');
        });

        // Finalized legacy sales already deducted inventory under the previous workflow.
        DB::table('daily_sales')
            ->where('status', 'finalized')
            ->update(['inventory_applied_at' => DB::raw('COALESCE(finalized_at, updated_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('daily_sales', function (Blueprint $table) {
            $table->dropColumn('inventory_applied_at');
        });
    }
};
