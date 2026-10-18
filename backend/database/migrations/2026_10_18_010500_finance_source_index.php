<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', fn (Blueprint $t) => $t->index(['company_id', 'source_type', 'source_id', 'status'], 'dc2_journal_source_integrity_idx'));
    }

    public function down(): void
    {
        Schema::table('journal_entries', fn (Blueprint $t) => $t->dropIndex('dc2_journal_source_integrity_idx'));
    }
};
