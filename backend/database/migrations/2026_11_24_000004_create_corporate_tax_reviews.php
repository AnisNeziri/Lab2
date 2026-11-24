<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_tax_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('accounting_period_id')->constrained();
            $table->string('fingerprint', 64);
            $table->json('snapshot');
            $table->foreignId('approved_by')->constrained('users');
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained();
            $table->timestamps();
            $table->index(['company_id', 'accounting_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_tax_reviews');
    }
};
