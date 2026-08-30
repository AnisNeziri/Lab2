<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand', 120)->nullable()->after('name');
            $table->json('attributes')->nullable()->after('description');
            $table->boolean('expiration_controlled')->default(false)->after('tracking_mode');
            $table->unsignedSmallInteger('default_shelf_life_days')->nullable()->after('expiration_controlled');
            $table->decimal('length_cm', 18, 3)->nullable()->after('weight_kg');
            $table->decimal('width_cm', 18, 3)->nullable()->after('length_cm');
            $table->decimal('height_cm', 18, 3)->nullable()->after('width_cm');
            $table->char('country_of_origin', 2)->nullable()->after('volume_m3');
            $table->string('hs_code', 32)->nullable()->after('country_of_origin');
            $table->string('lifecycle_status', 20)->default('active')->after('hs_code');
            $table->timestamp('discontinued_at')->nullable()->after('lifecycle_status');
            $table->timestamp('archived_at')->nullable()->after('discontinued_at');
            $table->index(['company_id', 'lifecycle_status'], 'product_company_lifecycle_idx');
            $table->index(['company_id', 'hs_code'], 'product_company_hs_idx');
        });
        $this->ensureTenantUnique('products', 'sku', 'product_company_sku_unique_v2');
        $this->ensureTenantUnique('products', 'barcode', 'product_company_barcode_unique_v2');

        // Existing batch-expiry products already express the same business
        // rule. Preserve them while exposing expiry control independently for
        // new lot-controlled products.
        DB::table('products')->where('tracking_mode', 'batch_expiry')->update([
            'expiration_controlled' => true,
        ]);

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('barcode', 100);
            $table->string('label', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'barcode'], 'product_barcode_company_unique');
            $table->index(['product_id', 'is_active'], 'product_barcode_product_active_idx');
        });

        Schema::table('product_suppliers', function (Blueprint $table) {
            $table->date('exchange_rate_date')->nullable()->after('exchange_rate_to_base');
        });
        Schema::table('product_supplier_price_history', function (Blueprint $table) {
            $table->date('exchange_rate_date')->nullable()->after('exchange_rate_to_base');
        });
        Schema::table('landed_costs', function (Blueprint $table) {
            $table->char('base_currency', 3)->default('EUR')->after('currency');
            $table->date('exchange_rate_date')->nullable()->after('exchange_rate_to_base');
        });

        DB::table('product_suppliers')
            ->where('currency', '!=', 'EUR')
            ->whereNull('exchange_rate_date')
            ->update(['exchange_rate_date' => DB::raw('DATE(COALESCE(last_price_changed_at, created_at))')]);
        DB::table('product_supplier_price_history')
            ->where('currency', '!=', 'EUR')
            ->whereNull('exchange_rate_date')
            ->update(['exchange_rate_date' => DB::raw('DATE(effective_at)')]);
        DB::table('landed_costs')
            ->where('currency', '!=', 'EUR')
            ->whereNull('exchange_rate_date')
            ->update(['exchange_rate_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('landed_costs', function (Blueprint $table) {
            $table->dropColumn(['base_currency', 'exchange_rate_date']);
        });
        Schema::table('product_supplier_price_history', function (Blueprint $table) {
            $table->dropColumn('exchange_rate_date');
        });
        Schema::table('product_suppliers', function (Blueprint $table) {
            $table->dropColumn('exchange_rate_date');
        });
        Schema::dropIfExists('product_barcodes');
        foreach (['product_company_sku_unique_v2', 'product_company_barcode_unique_v2'] as $indexName) {
            if (collect(Schema::getIndexes('products'))->contains(fn ($index) => ($index['name'] ?? null) === $indexName)) {
                Schema::table('products', fn (Blueprint $table) => $table->dropUnique($indexName));
            }
        }
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('product_company_hs_idx');
            $table->dropIndex('product_company_lifecycle_idx');
            $table->dropColumn([
                'brand', 'attributes', 'expiration_controlled', 'default_shelf_life_days',
                'length_cm', 'width_cm', 'height_cm', 'country_of_origin', 'hs_code',
                'lifecycle_status', 'discontinued_at', 'archived_at',
            ]);
        });
    }

    private function ensureTenantUnique(string $tableName, string $column, string $indexName): void
    {
        $indexes = collect(Schema::getIndexes($tableName));
        foreach ($indexes->filter(fn ($index) => ($index['unique'] ?? false) && ($index['columns'] ?? []) === [$column]) as $index) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropUnique($index['name']));
        }

        $hasTenantUnique = collect(Schema::getIndexes($tableName))->contains(
            fn ($index) => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['company_id', $column]
        );
        if ($hasTenantUnique) {
            return;
        }

        $duplicates = DB::table($tableName)
            ->whereNotNull($column)
            ->groupBy('company_id', $column)
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException("Duplicate {$column} values exist inside one company; resolve them before applying the product-master migration.");
        }

        Schema::table($tableName, fn (Blueprint $table) =>
            $table->unique(['company_id', $column], $indexName));
    }
};
