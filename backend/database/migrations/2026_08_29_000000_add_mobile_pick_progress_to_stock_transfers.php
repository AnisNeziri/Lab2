<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_transfer_items', 'picked_quantity')) {
                $table->decimal('picked_quantity', 18, 3)->default(0)->after('quantity');
                $table->foreignId('picked_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
                $table->timestamp('picked_at')->nullable()->after('picked_by');
            }
        });

        if (! Schema::hasTable('stock_transfer_pick_events')) {
            Schema::create('stock_transfer_pick_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('stock_transfer_item_id')->constrained()->cascadeOnDelete();
                $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
                $table->decimal('quantity', 18, 3);
                $table->json('trace_allocations')->nullable();
                $table->uuid('idempotency_key');
                $table->foreignId('picked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('picked_at');
                $table->timestamps();
                $table->unique(['company_id', 'idempotency_key'], 'stpe_company_key_unique');
                $table->index(['stock_transfer_id', 'stock_transfer_item_id'], 'stpe_transfer_item_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_pick_events');
        Schema::table('stock_transfer_items', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_transfer_items', 'picked_quantity')) {
                $table->dropConstrainedForeignId('picked_by');
                $table->dropColumn(['picked_quantity', 'picked_at']);
            }
        });
    }
};
