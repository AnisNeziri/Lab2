<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('business_name')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->string('tax_number')->nullable();
            $t->text('notes')->nullable();
            $t->decimal('current_debt', 15, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['company_id', 'name']);
            $t->index('phone');
            $t->index('email');
        });
        Schema::create('customer_debt_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('daily_sale_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type');
            $t->decimal('amount', 15, 2);
            $t->decimal('balance_before', 15, 2);
            $t->decimal('balance_after', 15, 2);
            $t->date('transaction_date');
            $t->string('payment_method')->nullable();
            $t->string('reference_number')->nullable();
            $t->text('note')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'customer_id', 'transaction_date'], 'debt_tx_company_customer_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_debt_transactions');
        Schema::dropIfExists('customers');
    }
};
