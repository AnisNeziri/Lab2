<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->unsignedInteger('tax_review_revision')->default(0);
        });
        Schema::create('corporate_tax_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('accounting_period_id')->constrained();
            $table->foreignId('approved_by')->constrained('users');
            $table->json('policy');
            $table->string('basis', 64);
            $table->boolean('historical')->default(false);
            $table->timestamps();
            $table->index(['company_id', 'accounting_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_tax_dispositions');
        Schema::table('accounting_periods', fn (Blueprint $table) => $table->dropColumn('tax_review_revision'));
    }
};
