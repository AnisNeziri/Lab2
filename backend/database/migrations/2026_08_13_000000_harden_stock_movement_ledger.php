<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('movement_code', 50)->nullable();
            $table->string('unit_snapshot', 30)->nullable();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();

            $table->foreign('performed_by', 'stock_move_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->unique(['company_id', 'idempotency_key'], 'stock_move_company_idem_uq');
            $table->index(['company_id', 'source_type', 'source_id'], 'stock_move_company_source_idx');
            $table->index(['company_id', 'occurred_at'], 'stock_move_company_occurred_idx');
        });

        DB::table('stock_movements')->update([
            'movement_code' => DB::raw("CASE WHEN type = 'in' THEN 'legacy_stock_in' ELSE 'legacy_stock_out' END"),
            'occurred_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign('stock_move_actor_fk');
            $table->dropUnique('stock_move_company_idem_uq');
            $table->dropIndex('stock_move_company_source_idx');
            $table->dropIndex('stock_move_company_occurred_idx');
            $table->dropColumn([
                'movement_code',
                'unit_snapshot',
                'source_type',
                'source_id',
                'performed_by',
                'idempotency_key',
                'occurred_at',
                'metadata',
            ]);
        });
    }
};
