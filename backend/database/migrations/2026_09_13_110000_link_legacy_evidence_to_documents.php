<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['quality_attachments', 'shipment_documents', 'expenses'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('document_version_id')->nullable()->constrained('document_versions')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        foreach (['quality_attachments', 'shipment_documents', 'expenses'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('document_version_id');
            });
        }
    }
};
