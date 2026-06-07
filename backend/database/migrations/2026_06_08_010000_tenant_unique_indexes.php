<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceUniqueIndex('categories', ['name'], ['company_id', 'name']);
        $this->replaceUniqueIndex('products', ['sku'], ['company_id', 'sku']);
        $this->replaceUniqueIndex('products', ['barcode'], ['company_id', 'barcode']);
        $this->replaceUniqueIndex('invoices', ['invoice_number'], ['company_id', 'invoice_number']);
    }

    public function down(): void
    {
        $this->replaceUniqueIndex('categories', ['company_id', 'name'], ['name']);
        $this->replaceUniqueIndex('products', ['company_id', 'sku'], ['sku']);
        $this->replaceUniqueIndex('products', ['company_id', 'barcode'], ['barcode']);
        $this->replaceUniqueIndex('invoices', ['company_id', 'invoice_number'], ['invoice_number']);
    }

    private function replaceUniqueIndex(string $table, array $from, array $to): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($from) {
                $blueprint->dropUnique($from);
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($to) {
                $blueprint->unique($to);
            });
        } catch (\Throwable) {
        }
    }
};
