<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Validate both barcode namespaces before DDL. MySQL can auto-commit
        // schema changes, so a conflict must fail without leaving half a
        // migration behind.
        $seen = [];
        foreach (DB::table('products')->whereNotNull('barcode')->orderBy('id')->get(['id', 'company_id', 'barcode']) as $row) {
            $this->rememberBarcode($seen, (int) $row->company_id, (string) $row->barcode, "product {$row->id} primary barcode");
        }
        if (Schema::hasTable('product_barcodes')) {
            foreach (DB::table('product_barcodes')->orderBy('id')->get(['id', 'company_id', 'barcode']) as $row) {
                $this->rememberBarcode($seen, (int) $row->company_id, (string) $row->barcode, "alternative barcode {$row->id}");
            }
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->char('base_currency', 3)->default('EUR')->after('address');
        });
        DB::table('companies')->whereNull('base_currency')->orWhere('base_currency', '')->update(['base_currency' => 'EUR']);

        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode_normalized', 100)->nullable()->after('barcode');
        });
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->string('barcode_normalized', 100)->nullable()->after('barcode');
        });

        foreach (DB::table('products')->whereNotNull('barcode')->orderBy('id')->get(['id', 'barcode']) as $row) {
            DB::table('products')->where('id', $row->id)->update([
                'barcode' => trim((string) $row->barcode),
                'barcode_normalized' => $this->normalizeBarcode($row->barcode),
            ]);
        }
        foreach (DB::table('product_barcodes')->orderBy('id')->get(['id', 'barcode']) as $row) {
            DB::table('product_barcodes')->where('id', $row->id)->update([
                'barcode' => trim((string) $row->barcode),
                'barcode_normalized' => $this->normalizeBarcode($row->barcode),
            ]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['company_id', 'barcode_normalized'], 'product_company_barcode_identity_unique');
        });
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->unique(['company_id', 'barcode_normalized'], 'alt_barcode_company_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->dropUnique('alt_barcode_company_identity_unique');
            $table->dropColumn('barcode_normalized');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('product_company_barcode_identity_unique');
            $table->dropColumn('barcode_normalized');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('base_currency');
        });
    }

    private function rememberBarcode(array &$seen, int $companyId, string $value, string $source): void
    {
        $identity = $this->normalizeBarcode($value);
        if ($identity === null) {
            return;
        }
        $key = $companyId.'|'.$identity;
        if (isset($seen[$key])) {
            throw new RuntimeException("Barcode identity conflict between {$seen[$key]} and {$source}. Resolve it before migration.");
        }
        $seen[$key] = $source;
    }

    private function normalizeBarcode(mixed $value): ?string
    {
        $display = trim((string) ($value ?? ''));
        if ($display === '') {
            return null;
        }

        return mb_strtolower((string) preg_replace('/\s+/u', ' ', $display));
    }
};
