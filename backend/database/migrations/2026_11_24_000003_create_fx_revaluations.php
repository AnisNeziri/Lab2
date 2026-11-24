<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_revaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('accounting_period_id')->constrained();
            $table->date('accounting_date');
            $table->string('fingerprint', 64);
            $table->json('snapshot');
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained();
            $table->foreignId('reversal_journal_id')->nullable()->unique()->constrained('journal_entries');
            $table->foreignId('approved_by')->constrained('users');
            $table->string('reason', 1000);
            $table->timestamps();
            $table->index(['company_id', 'accounting_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_revaluations');
    }
};
