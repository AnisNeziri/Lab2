<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('timezone', 64)->nullable();
            $t->date('operational_cutover_date')->nullable();
        });
        Schema::create('onboarding_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->uuid('public_id')->unique();
            $t->string('name', 160);
            $t->string('source_period', 160);
            $t->date('cutover_date');
            $t->string('currency', 3);
            $t->string('timezone', 64);
            $t->string('status', 32)->default('draft');
            $t->string('stage', 40)->default('company');
            $t->unsignedInteger('revision')->default(0);
            $t->json('expected_totals');
            $t->boolean('separate_reviewer_required')->default(false);
            $t->string('review_fingerprint', 64)->nullable();
            $t->string('approved_fingerprint', 64)->nullable();
            $t->json('review')->nullable();
            $t->json('verification')->nullable();
            $t->json('restore_verification')->nullable();
            foreach (['created_by', 'reviewed_by', 'approved_by', 'locked_by'] as $key) {
                $t->foreignId($key)->nullable()->constrained('users')->nullOnDelete();
            }
            foreach (['reviewed_at', 'approved_at', 'posting_started_at', 'posted_at', 'verified_at', 'locked_at', 'cancelled_at'] as $key) {
                $t->timestamp($key)->nullable();
            }
            $t->unsignedBigInteger('pre_backup_id')->nullable();
            $t->unsignedBigInteger('post_backup_id')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });
        Schema::create('onboarding_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('onboarding_session_id')->constrained()->cascadeOnDelete();
            $t->string('domain', 40);
            $t->string('filename', 180);
            $t->string('checksum', 64);
            $t->unsignedInteger('attempt')->default(1);
            $t->string('format', 10);
            $t->string('delimiter', 1)->default(',');
            $t->longText('source_base64');
            $t->json('headers');
            $t->json('mapping');
            $t->json('options');
            $t->unsignedInteger('row_count')->default(0);
            $t->unsignedInteger('parsed_count')->default(0);
            $t->unsignedInteger('accepted_count')->default(0);
            $t->unsignedInteger('warning_count')->default(0);
            $t->unsignedInteger('error_count')->default(0);
            $t->boolean('is_active')->default(true);
            $t->string('status', 24)->default('staged');
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['onboarding_session_id', 'domain', 'checksum', 'attempt'], 'onboarding_file_attempt_unique');
            $t->index(['company_id', 'onboarding_session_id', 'is_active'], 'onboarding_batch_active_idx');
        });
        Schema::create('onboarding_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('onboarding_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('onboarding_batch_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('line_number');
            $t->string('domain', 40);
            $t->string('external_ref', 160)->nullable();
            $t->json('raw');
            $t->json('normalized')->nullable();
            $t->json('overrides')->nullable();
            $t->json('resolution')->nullable();
            $t->unsignedBigInteger('match_id')->nullable();
            $t->json('candidates')->nullable();
            $t->json('errors')->nullable();
            $t->json('warnings')->nullable();
            $t->string('status', 24)->default('unvalidated');
            $t->string('posted_entity_type', 60)->nullable();
            $t->unsignedBigInteger('posted_entity_id')->nullable();
            $t->json('posting_result')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['onboarding_batch_id', 'line_number']);
            $t->index(['company_id', 'onboarding_session_id', 'domain', 'status'], 'onboarding_row_review_idx');
            $t->index(['onboarding_session_id', 'domain', 'external_ref'], 'onboarding_row_reference_idx');
        });
        Schema::create('onboarding_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('onboarding_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 80);
            $t->json('context');
            $t->timestamp('created_at');
            $t->index(['company_id', 'onboarding_session_id', 'id']);
        });
        Schema::create('onboarding_backups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('onboarding_session_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 8);
            $t->string('checksum', 64);
            $t->longText('encrypted_contents');
            $t->string('operational_fingerprint', 64);
            $t->json('verification');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::table('purchase_order_payments', function (Blueprint $t) {
            $t->unsignedBigInteger('purchase_order_id')->nullable()->change();
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('onboarding_session_id')->nullable()->constrained()->restrictOnDelete();
        });
        (new RolePermissionSeeder)->run(true);
    }

    public function down(): void
    {
        Schema::table('purchase_order_payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('onboarding_session_id');
            $t->dropConstrainedForeignId('supplier_id');
        });
        foreach (['onboarding_backups', 'onboarding_events', 'onboarding_rows', 'onboarding_batches', 'onboarding_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['timezone', 'operational_cutover_date']));
        // Do not force existing, valid supplier openings back into a non-null PO.
    }
};
