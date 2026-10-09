<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategic_simulations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->uuid('version')->unique();
            $t->string('engine_version');
            $t->string('request_key', 64);
            $t->string('name', 160);
            $t->text('description')->nullable();
            $t->string('status', 24)->default('QUEUED');
            $t->string('stage', 40)->default('preparing_baseline');
            $t->json('definition');
            $t->json('baseline')->nullable();
            $t->timestamp('baseline_at')->nullable();
            $t->json('result')->nullable();
            $t->json('response_plan')->nullable();
            $t->json('audit')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status', 'id'], 'simulation_company_status');
            $t->index(['company_id', 'request_key'], 'simulation_company_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategic_simulations');
    }
};
