<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_units')) {
            return;
        }

        // Variable packages cannot safely be converted automatically. Keep
        // their historical snapshots on completed documents, but stop them
        // from being offered for any new purchase, sale, or invoice.
        DB::table('product_units')
            ->where('conversion_mode', '!=', 'fixed')
            ->update([
                'is_active' => false,
                'allow_purchase' => false,
                'allow_sale' => false,
                'is_default_purchase' => false,
                'is_default_sale' => false,
            ]);

        DB::table('product_units')
            ->where('conversion_mode', 'fixed')
            ->where('factor_to_base', '>', 0)
            ->update([
                'allow_purchase' => true,
                'allow_sale' => true,
                'is_default_purchase' => false,
                'is_default_sale' => false,
            ]);
    }

    public function down(): void
    {
        // Historical variable definitions are intentionally not reactivated.
    }
};
