<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->boolean('requires_review')->default(false);
            $t->timestamps();
            $t->unique(['company_id', 'name']);
        });
        Schema::create('document_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('max_file_mb')->default(20);
            $t->json('allowed_extensions')->nullable();
            $t->unsignedInteger('expiry_notice_days')->default(30);
            $t->timestamps();
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid');
            $t->string('reference')->nullable();
            $t->string('title');
            $t->text('description')->nullable();
            $t->foreignId('document_type_id')->constrained();
            $t->string('status', 30)->default('active');
            $t->string('confidentiality', 20)->default('internal');
            $t->unsignedInteger('current_version')->default(1);
            $t->string('issuer')->nullable();
            $t->date('document_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->date('retain_until')->nullable();
            $t->boolean('legal_hold')->default(false);
            $t->text('hold_reason')->nullable();
            $t->json('tags')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('archived_at')->nullable();
            $t->string('legacy_key')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'uuid']);
            $t->unique(['company_id', 'legacy_key']);
            $t->index(['company_id', 'status', 'expiry_date']);
            $t->index(['company_id', 'document_type_id']);
        });
        Schema::create('document_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('filename');
            $t->string('mime_type');
            $t->unsignedBigInteger('size');
            $t->char('checksum', 64);
            $t->string('provider', 20)->default('local');
            $t->string('storage_key');
            $t->text('change_note')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->string('integrity_status', 20)->nullable();
            $t->timestamps();
            $t->unique(['document_id', 'version']);
            $t->index(['company_id', 'checksum']);
        });
        Schema::create('document_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->string('entity_type', 50);
            $t->unsignedBigInteger('entity_id');
            $t->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['document_id', 'entity_type', 'entity_id'], 'doc_link_unique');
            $t->index(['company_id', 'entity_type', 'entity_id'], 'doc_entity_index');
        });
        Schema::create('document_requirements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('entity_type', 50);
            $t->foreignId('document_type_id')->constrained();
            $t->boolean('requires_approval')->default(false);
            $t->timestamps();
            $t->unique(['company_id', 'entity_type', 'document_type_id'], 'doc_requirement_unique');
        });
    }

    public function down(): void
    {
        foreach (['document_requirements', 'document_links', 'document_versions', 'documents', 'document_settings', 'document_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
