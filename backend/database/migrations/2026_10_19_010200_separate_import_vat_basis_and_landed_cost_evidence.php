<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $t) {
            $t->decimal('import_taxable_base', 18, 2)->nullable();
            $t->decimal('import_taxable_base_eur', 18, 2)->nullable();
        });
        Schema::table('landed_costs', function (Blueprint $t) {
            $t->foreignId('source_expense_id')->nullable()->constrained('expenses')->restrictOnDelete();
            $t->json('tax_basis_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('landed_costs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('source_expense_id');
            $t->dropColumn('tax_basis_snapshot');
        });
        Schema::table('expenses', fn (Blueprint $t) => $t->dropColumn(['import_taxable_base', 'import_taxable_base_eur']));
    }
};
