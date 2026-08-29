<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_count_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('count_number', 40);
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('status', 30)->default('in_progress');
            $table->json('stock_states')->nullable();
            $table->timestamp('frozen_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->text('approval_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'count_number'], 'inventory_count_number_unique');
            $table->index(['company_id', 'warehouse_id', 'status'], 'inventory_count_status_idx');
        });

        Schema::create('inventory_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->unsignedBigInteger('location_key')->default(0);
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->unsignedBigInteger('lot_key')->default(0);
            $table->string('stock_state', 20)->default('available');
            $table->decimal('expected_quantity', 18, 3);
            $table->decimal('counted_quantity', 18, 3)->nullable();
            $table->decimal('variance_quantity', 18, 3)->nullable();
            $table->unsignedInteger('count_round')->default(1);
            $table->boolean('requires_recount')->default(false);
            $table->foreignId('last_counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_counted_at')->nullable();
            $table->foreignId('adjustment_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->timestamps();
            $table->unique(
                ['inventory_count_session_id', 'product_id', 'location_key', 'lot_key', 'stock_state'],
                'inventory_count_item_scope_unique'
            );
            $table->index(['company_id', 'product_id'], 'inventory_count_product_idx');
        });

        Schema::create('inventory_count_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_count_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('count_round');
            $table->string('entry_type', 20)->default('count');
            $table->decimal('counted_quantity', 18, 3)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entered_at');
            $table->timestamps();
            $table->index(['inventory_count_item_id', 'count_round'], 'inventory_count_entry_round_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_entries');
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_count_sessions');
    }
};
