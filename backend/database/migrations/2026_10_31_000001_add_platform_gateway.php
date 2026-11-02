<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->json('platform_settings')->nullable());
        Schema::create('service_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('name', 120);
            $t->string('status')->default('active');
            $t->json('scopes');
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('rotated_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });
        Schema::create('business_search_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('entity_type', 50);
            $t->unsignedBigInteger('entity_id');
            $t->string('title');
            $t->string('identifier')->default('');
            $t->text('search_text');
            $t->json('embedding');
            $t->string('model_version', 80);
            $t->string('index_version', 40);
            $t->timestamp('source_updated_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'entity_type', 'entity_id'], 'business_search_source');
            $t->index(['company_id', 'model_version', 'entity_type'], 'business_search_scope');
        });
        Schema::create('platform_spans', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('trace_id', 32)->index();
            $t->string('span_id', 16);
            $t->string('parent_span_id', 16)->nullable();
            $t->string('name', 150);
            $t->string('status', 20);
            $t->double('duration_ms');
            $t->json('attributes');
            $t->string('start_nano', 24);
            $t->string('end_nano', 24);
            $t->timestamp('created_at')->index();
        });
        Schema::table('webhook_endpoints', fn (Blueprint $t) => $t->string('contract_version')->default('legacy'));
        Schema::table('webhook_deliveries', fn (Blueprint $t) => $t->longText('payload_body')->nullable());
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', fn (Blueprint $t) => $t->dropColumn('payload_body'));
        Schema::table('webhook_endpoints', fn (Blueprint $t) => $t->dropColumn('contract_version'));
        Schema::dropIfExists('platform_spans');
        Schema::dropIfExists('business_search_documents');
        Schema::dropIfExists('service_accounts');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('platform_settings'));
    }
};
