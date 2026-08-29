<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_cost_accounting_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landed_cost_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landed_cost_allocation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('receipt_quantity', 18, 3);
            $table->decimal('remaining_quantity_at_post', 18, 3);
            $table->decimal('quantity_recognized_in_cogs', 18, 3);
            $table->decimal('inventory_adjustment_amount', 22, 6)->default(0);
            $table->decimal('cogs_adjustment_amount', 22, 6)->default(0);
            $table->string('calculation_method', 50);
            $table->json('calculation_evidence')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->unique('landed_cost_allocation_id', 'lc_accounting_allocation_unique');
            $table->index(['company_id', 'posted_at'], 'lc_accounting_period_idx');
            $table->index(['company_id', 'product_id', 'posted_at'], 'lc_accounting_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_accounting_entries');
    }
};
