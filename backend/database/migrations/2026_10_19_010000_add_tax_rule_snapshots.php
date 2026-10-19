<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('code', 40);
            $t->string('name', 160);
            $t->decimal('rate', 7, 4);
            $t->string('treatment', 40);
            $t->boolean('sales_enabled')->default(true);
            $t->boolean('purchases_enabled')->default(true);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('output_account_id')->nullable()->constrained('accounting_accounts')->restrictOnDelete();
            $t->foreignId('input_account_id')->nullable()->constrained('accounting_accounts')->restrictOnDelete();
            $t->string('sef_tax_group', 1)->nullable();
            $t->string('legal_reference', 1000)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'code', 'effective_from']);
            $t->index(['company_id', 'active', 'effective_from']);
        });
        Schema::table('products', fn (Blueprint $t) => $t->foreignId('default_tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete());
        Schema::table('invoice_items', function (Blueprint $t) {
            $t->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $t->json('tax_snapshot')->nullable();
            $t->string('pricing_mode', 12)->nullable();
            $t->decimal('pricing_discount_amount', 18, 2)->nullable();
            $t->unsignedSmallInteger('sef_category_id')->nullable();
        });
        Schema::table('expenses', function (Blueprint $t) {
            $t->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $t->json('tax_snapshot')->nullable();
        });
        Schema::table('invoice_profiles', fn (Blueprint $t) => $t->date('tax_codes_required_from')->nullable());
        app(RolePermissionSeeder::class)->run(true);
    }

    public function down(): void
    {
        Schema::table('invoice_profiles', fn (Blueprint $t) => $t->dropColumn('tax_codes_required_from'));
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tax_code_id');
            $t->dropColumn('tax_snapshot');
        });
        Schema::table('invoice_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tax_code_id');
            $t->dropColumn(['tax_snapshot', 'pricing_mode', 'pricing_discount_amount', 'sef_category_id']);
        });
        Schema::table('products', fn (Blueprint $t) => $t->dropConstrainedForeignId('default_tax_code_id'));
        Schema::dropIfExists('tax_codes');
    }
};
