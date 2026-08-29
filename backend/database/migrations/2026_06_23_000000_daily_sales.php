<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('sale_number');
            $table->date('sale_date');
            $table->string('customer_name')->nullable();
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->string('signature_name')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedInteger('total_quantity')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'sale_number']);
            $table->index(['company_id', 'sale_date', 'status']);
        });

        Schema::create('daily_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('unit')->default('pcs');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index(['daily_sale_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_sale_items');
        Schema::dropIfExists('daily_sales');
    }
};
