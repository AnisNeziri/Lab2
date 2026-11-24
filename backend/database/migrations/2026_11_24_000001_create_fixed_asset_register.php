<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name', 120);
            $table->decimal('minimum_cost', 16, 2);
            $table->unsignedSmallInteger('useful_life_months');
            $table->string('policy_reference', 500);
            $table->foreignId('approved_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('category_id')->constrained('fixed_asset_categories');
            $table->string('asset_number', 60);
            $table->string('name', 160);
            $table->date('available_for_use_on')->nullable();
            $table->decimal('residual_value', 16, 2);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'asset_number']);
        });
        Schema::create('fixed_asset_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('fixed_asset_id')->constrained();
            $table->foreignId('expense_id')->nullable()->unique()->constrained();
            $table->foreignId('sale_invoice_id')->nullable()->unique()->constrained('invoices');
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained();
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('fixed_asset_entries');
            $table->string('kind', 24);
            $table->date('effective_on');
            $table->decimal('cost_delta', 16, 2)->default(0);
            $table->decimal('depreciation_delta', 16, 2)->default(0);
            $table->decimal('impairment_delta', 16, 2)->default(0);
            $table->string('request_key', 80)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->string('reason', 1000);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'request_key']);
            $table->index(['company_id', 'fixed_asset_id', 'effective_on'], 'asset_entries_company_asset_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_entries');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_categories');
    }
};
