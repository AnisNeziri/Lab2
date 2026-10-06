<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained();
            $t->string('name'); $t->text('description')->nullable(); $t->boolean('enabled')->default(false);
            $t->string('trigger'); $t->json('conditions'); $t->json('actions'); $t->json('schedule')->nullable();
            $t->string('priority')->default('normal'); $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1); $t->timestamp('last_run_at')->nullable();
            $t->unsignedBigInteger('run_count')->default(0); $t->unsignedBigInteger('failure_count')->default(0);
            $t->unsignedBigInteger('event_cursor')->default(0); $t->timestamps();
            $t->index(['company_id','trigger','enabled'], 'automation_trigger_idx');
        });
        Schema::create('automation_versions', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('automation_id')->constrained();
            $t->unsignedInteger('version'); $t->json('definition'); $t->foreignId('created_by')->constrained('users');
            $t->timestamps(); $t->unique(['automation_id','version']);
        });
        Schema::create('automation_executions', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('automation_id')->constrained();
            $t->unsignedInteger('version'); $t->foreignId('business_event_id')->nullable()->constrained();
            $t->string('execution_key',64); $t->string('status')->default('queued');
            $t->json('context'); $t->json('conditions_evaluated')->nullable(); $t->json('results')->nullable();
            $t->string('correlation_id'); $t->unsignedInteger('depth')->default(0);
            $t->unsignedInteger('attempts')->default(0); $t->timestamp('next_retry_at')->nullable();
            $t->timestamp('started_at')->nullable(); $t->timestamp('completed_at')->nullable();
            $t->unsignedInteger('duration_ms')->nullable(); $t->text('error')->nullable(); $t->timestamps();
            $t->unique(['company_id','execution_key']); $t->index(['company_id','status']);
        });
        Schema::create('operational_tasks', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->string('title'); $t->text('description')->nullable();
            $t->string('status')->default('open'); $t->string('priority')->default('normal');
            $t->foreignId('assigned_user_id')->nullable()->constrained('users'); $t->string('assigned_role')->nullable();
            $t->timestamp('due_at')->nullable(); $t->string('source_type')->nullable(); $t->unsignedBigInteger('source_id')->nullable();
            $t->foreignId('automation_id')->nullable()->constrained(); $t->foreignId('created_by')->nullable()->constrained('users');
            $t->string('dedupe_key',64)->nullable(); $t->timestamp('completed_at')->nullable();
            $t->timestamp('escalated_at')->nullable(); $t->json('outcome')->nullable(); $t->timestamps();
            $t->unique(['company_id','dedupe_key']); $t->index(['company_id','status','due_at']);
        });
    }
    public function down(): void
    {
        foreach (['operational_tasks','automation_executions','automation_versions','automations'] as $table) Schema::dropIfExists($table);
    }
};
