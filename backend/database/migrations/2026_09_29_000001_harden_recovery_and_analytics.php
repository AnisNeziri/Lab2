<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('analytics_datasets', function (Blueprint $table) {
            $table->dropUnique(['version']);
            $table->unique(['company_id', 'version']);
        });
        Schema::create('maintenance_health', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('task', 60);
            $table->string('status', 20);
            $table->timestamp('last_attempt_at');
            $table->timestamp('last_success_at')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->unique(['company_id', 'task']);
        });
    }

    public function down(): void
    {
        if (DB::table('analytics_datasets')->groupBy('version')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new \RuntimeException('Cannot roll back tenant-local dataset versions while restored copies exist. No data was removed.');
        }
        Schema::table('analytics_datasets', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'version']);
            $table->unique('version');
        });
        Schema::dropIfExists('maintenance_health');
    }
};
