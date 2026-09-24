<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('supplier_match_quantity_tolerance', 18, 3)->nullable();
            $table->decimal('supplier_match_price_tolerance', 18, 4)->nullable();
            $table->decimal('supplier_match_tax_tolerance', 18, 2)->nullable();
        });

        Schema::create('accounting_recovery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accounting_exception_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attempted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();
            $table->index(['company_id', 'accounting_exception_id', 'attempted_at'], 'accounting_recovery_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_recovery_attempts');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn([
            'supplier_match_quantity_tolerance', 'supplier_match_price_tolerance', 'supplier_match_tax_tolerance',
        ]));
    }
};
