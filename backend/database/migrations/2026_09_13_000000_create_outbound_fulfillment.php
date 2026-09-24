<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_orders', function(Blueprint $t){
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('customer_id')->constrained();
            $t->string('order_number'); $t->date('order_date'); $t->date('requested_delivery_date')->nullable();
            $t->unsignedTinyInteger('priority')->default(2); $t->string('currency',3); $t->string('payment_type')->default('cash');
            $t->string('status')->default('draft'); $t->foreignId('warehouse_id')->nullable()->constrained();
            $t->decimal('total_amount',18,2)->default(0); $t->decimal('committed_amount',18,2)->default(0);
            $t->foreignId('approval_request_id')->nullable()->constrained(); $t->string('credit_signature',64)->nullable();
            $t->text('notes')->nullable(); $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamp('confirmed_at')->nullable(); $t->timestamp('completed_at')->nullable();
            $t->string('idempotency_key',100); $t->string('request_fingerprint',64); $t->json('metadata')->nullable(); $t->timestamps();
            $t->unique(['company_id','order_number']); $t->unique(['company_id','idempotency_key']);
            $t->index(['company_id','status','priority','requested_delivery_date'],'so_queue_idx');
        });
        Schema::create('sales_order_items',function(Blueprint $t){
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('sales_order_id')->constrained(); $t->foreignId('product_id')->constrained();
            $t->string('unit',30); $t->decimal('quantity',18,3); $t->decimal('base_quantity',18,3); $t->decimal('conversion_factor',18,6)->default(1);
            $t->decimal('unit_price',18,2); $t->decimal('line_total',18,2);
            foreach(['reserved','allocated','picked','packed','dispatched','delivered','returned'] as $s)$t->decimal($s.'_quantity',18,3)->default(0);
            $t->timestamps(); $t->index(['company_id','product_id']);
        });
        Schema::create('pick_waves',function(Blueprint $t){
            $t->id(); $t->foreignId('company_id')->constrained(); $t->string('reference'); $t->foreignId('warehouse_id')->constrained();
            $t->string('status')->default('open'); $t->foreignId('created_by')->nullable()->constrained('users'); $t->timestamps();
        });
        Schema::create('pick_tasks',function(Blueprint $t){
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('sales_order_id')->constrained(); $t->foreignId('warehouse_id')->constrained();
            $t->foreignId('pick_wave_id')->nullable()->constrained(); $t->foreignId('assigned_to')->nullable()->constrained('users');
            $t->string('reference'); $t->unsignedTinyInteger('priority')->default(2); $t->string('status')->default('pending');
            $t->timestamp('started_at')->nullable(); $t->timestamp('completed_at')->nullable(); $t->timestamps(); $t->index(['company_id','warehouse_id','status']);
        });
        Schema::create('outbound_allocations',function(Blueprint $t){
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('sales_order_id')->constrained(); $t->foreignId('sales_order_item_id')->constrained();
            $t->foreignId('warehouse_id')->constrained(); $t->foreignId('location_id')->nullable()->constrained('warehouse_locations');
            $t->foreignId('inventory_lot_id')->nullable()->constrained(); $t->foreignId('pick_task_id')->nullable()->constrained();
            $t->decimal('quantity',18,3); foreach(['picked','packed','dispatched','delivered','returned'] as $s)$t->decimal($s.'_quantity',18,3)->default(0);
            $t->string('status')->default('reserved'); $t->text('exception_reason')->nullable(); $t->foreignId('created_by')->nullable()->constrained('users'); $t->timestamps();
            $t->index(['company_id','warehouse_id','status'],'oa_queue_idx');
        });
        Schema::create('outbound_dispatches',function(Blueprint $t){
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('sales_order_id')->constrained();
            $t->string('reference'); $t->string('status')->default('dispatched'); $t->string('driver')->nullable(); $t->string('vehicle')->nullable();
            $t->string('recipient')->nullable(); $t->string('proof_reference')->nullable(); $t->text('notes')->nullable(); $t->text('failure_reason')->nullable();
            $t->timestamp('dispatched_at'); $t->timestamp('delivered_at')->nullable(); $t->decimal('total_amount',18,2)->default(0);
            $t->foreignId('daily_sale_id')->nullable()->constrained(); $t->foreignId('customer_debt_transaction_id')->nullable()->constrained();
            $t->foreignId('created_by')->nullable()->constrained('users'); $t->timestamps(); $t->index(['company_id','status']);
        });
        Schema::create('outbound_packages',function(Blueprint $t){
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('sales_order_id')->constrained();
            $t->foreignId('outbound_dispatch_id')->nullable()->constrained(); $t->string('reference'); $t->string('type')->default('box');
            $t->decimal('weight',12,3)->nullable(); $t->string('dimensions')->nullable(); $t->text('notes')->nullable();
            $t->foreignId('packed_by')->nullable()->constrained('users'); $t->timestamp('packed_at'); $t->timestamps();
        });
        Schema::create('outbound_package_items',function(Blueprint $t){
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('outbound_package_id')->constrained();
            $t->foreignId('outbound_allocation_id')->constrained(); $t->foreignId('daily_sale_item_id')->nullable()->constrained(); $t->decimal('quantity',18,3); $t->decimal('delivered_quantity',18,3)->default(0); $t->timestamps();
        });
        Schema::create('outbound_returns',function(Blueprint $t){
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('sales_order_id')->constrained(); $t->foreignId('outbound_allocation_id')->constrained();
            $t->foreignId('inventory_return_id')->nullable()->constrained(); $t->string('reference'); $t->decimal('quantity',18,3);
            $t->string('status')->default('requested'); $t->text('reason'); $t->string('disposition')->nullable(); $t->timestamp('received_at')->nullable();
            $t->timestamp('resolved_at')->nullable(); $t->foreignId('created_by')->nullable()->constrained('users'); $t->timestamps();
        });
        Schema::create('outbound_actions',function(Blueprint $t){
            $t->id(); $t->foreignId('company_id')->constrained(); $t->string('idempotency_key',100); $t->string('fingerprint',64);
            $t->string('action'); $t->foreignId('sales_order_id')->constrained(); $t->json('result')->nullable(); $t->timestamps(); $t->unique(['company_id','idempotency_key']);
        });
    }
    public function down(): void { foreach(['outbound_actions','outbound_returns','outbound_package_items','outbound_packages','outbound_dispatches','outbound_allocations','pick_tasks','pick_waves','sales_order_items','sales_orders'] as $table)Schema::dropIfExists($table); }
};
