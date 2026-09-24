<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 20);
            $table->string('backup_type', 40);
            $table->string('status', 20)->default('started');
            $table->json('modules')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('verification_result', 40)->nullable();
            $table->string('error_summary', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'completed_at'], 'backup_run_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
