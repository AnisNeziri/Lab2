<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pilot_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained();
            $t->unsignedInteger('revision')->default(1);
            $t->string('status', 20)->default('draft');
            $t->date('cutover_date')->nullable();
            $t->string('timezone', 64)->nullable();
            $t->json('business_days')->nullable();
            $t->json('workstreams')->nullable();
            $t->json('participants')->nullable();
            $t->json('source_attestation')->nullable();
            $t->boolean('telemetry_enabled')->default(false);
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('pilot_feedback', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('role', 32);
            $t->string('category', 32);
            $t->string('page', 100);
            $t->string('release_version', 60);
            $t->string('entity_type', 40)->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->text('description')->nullable();
            $t->string('reference', 40)->nullable();
            $t->string('priority', 12)->default('untriaged');
            $t->string('status', 20)->default('open');
            $t->foreignId('triaged_by')->nullable()->constrained('users');
            $t->text('resolution')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status', 'priority', 'id'], 'pilot_feedback_review_idx');
        });
        Schema::create('pilot_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('kind', 40);
            $t->date('observed_on');
            $t->string('outcome', 24);
            $t->boolean('is_historical')->default(false);
            $t->string('entity_type', 40)->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->foreignId('document_id')->nullable()->constrained('documents');
            $t->json('checks');
            $t->text('notes')->nullable();
            $t->foreignId('prepared_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'kind', 'observed_on', 'id'], 'pilot_evidence_kind_idx');
        });
        Schema::create('pilot_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('kind', 20);
            $t->boolean('is_historical')->default(false);
            $t->date('period_start');
            $t->date('period_end');
            $t->timestamp('captured_at');
            $t->string('fingerprint', 64);
            $t->json('report');
            $t->foreignId('captured_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['company_id', 'fingerprint']);
            $t->index(['company_id', 'kind', 'id']);
        });
        Schema::create('pilot_incidents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('reference', 40);
            $t->string('route_key', 100);
            $t->string('exception_type', 80);
            $t->unsignedSmallInteger('http_status');
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->unique(['company_id', 'reference']);
            $t->index(['company_id', 'occurred_at']);
        });
        Schema::create('pilot_request_aggregates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->date('day');
            $t->string('route_key', 100);
            $t->string('method', 8);
            $t->string('role', 32);
            $t->unsignedSmallInteger('http_status');
            $t->unsignedBigInteger('requests')->default(0);
            $t->unsignedBigInteger('duration_ms')->default(0);
            $t->unsignedInteger('max_ms')->default(0);
            $t->unsignedBigInteger('response_bytes')->default(0);
            foreach (['under_100', 'under_300', 'under_500', 'under_1000', 'under_3000', 'under_10000', 'over_10000'] as $bucket) {
                $t->unsignedBigInteger($bucket)->default(0);
            }
            $t->unique(['company_id', 'day', 'route_key', 'method', 'role', 'http_status'], 'pilot_request_dimensions');
        });
        Schema::create('pilot_daily_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->date('day');
            $t->foreignId('user_id')->constrained();
            $t->string('role', 32);
            $t->unique(['company_id', 'day', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['pilot_daily_users', 'pilot_request_aggregates', 'pilot_incidents', 'pilot_reports', 'pilot_evidence', 'pilot_feedback', 'pilot_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
