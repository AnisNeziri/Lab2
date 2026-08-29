<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('tracking_mode_snapshot', 20);
            $table->string('identity_key', 191);
            $table->string('lot_number', 100)->nullable();
            $table->string('serial_number', 191)->nullable();
            $table->string('supplier_batch', 100)->nullable();
            $table->date('manufactured_at')->nullable();
            $table->date('expiry_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'product_id', 'identity_key'], 'inventory_lot_identity_unique');
            // NULL serials do not collide, while a real serial is unique for
            // the company for its complete audit lifetime.
            $table->unique(['company_id', 'serial_number'], 'inventory_serial_company_unique');
            $table->index(['company_id', 'product_id', 'expiry_at'], 'inventory_lot_fefo_idx');
        });

        Schema::create('inventory_trace_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->unsignedBigInteger('location_key')->default(0);
            $table->string('stock_state', 20)->default('available');
            $table->decimal('quantity', 18, 3)->default(0);
            $table->timestamps();
            $table->unique(
                ['inventory_lot_id', 'warehouse_id', 'location_key', 'stock_state'],
                'inventory_trace_balance_unique'
            );
            $table->index(
                ['company_id', 'warehouse_id', 'location_id', 'stock_state'],
                'inventory_trace_locator_idx'
            );
        });

        Schema::create('stock_movement_traces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->timestamps();
            $table->unique(['stock_movement_id', 'inventory_lot_id'], 'stock_movement_lot_unique');
            $table->index(['company_id', 'inventory_lot_id'], 'stock_movement_trace_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movement_traces');
        Schema::dropIfExists('inventory_trace_balances');
        Schema::dropIfExists('inventory_lots');
    }
};
