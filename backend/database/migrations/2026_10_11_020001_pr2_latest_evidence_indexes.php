<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Baseline EXPLAIN shows company/date scans plus temporary ordering over large frozen JSON.
        Schema::table('financial_intelligence_snapshots', fn (Blueprint $t) => $t->index(['company_id', 'id'], 'pr2_finance_latest_idx'));
        Schema::table('customer_sales_snapshots', fn (Blueprint $t) => $t->index(['company_id', 'id'], 'pr2_customer_latest_idx'));
    }

    public function down(): void
    {
        Schema::table('financial_intelligence_snapshots', fn (Blueprint $t) => $t->dropIndex('pr2_finance_latest_idx'));
        Schema::table('customer_sales_snapshots', fn (Blueprint $t) => $t->dropIndex('pr2_customer_latest_idx'));
    }
};
