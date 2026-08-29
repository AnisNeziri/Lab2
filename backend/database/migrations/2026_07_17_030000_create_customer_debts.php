<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_debts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('customer_name');
            $t->timestamps();
            $t->unique(['company_id', 'customer_name']);
        });
        Schema::create('customer_debt_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_debt_id')->constrained()->cascadeOnDelete();
            $t->date('entry_date');
            $t->string('description');
            $t->decimal('amount_owed', 12, 2)->default(0);
            $t->decimal('amount_paid', 12, 2)->default(0);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_debt_entries');
        Schema::dropIfExists('customer_debts');
    }
};
