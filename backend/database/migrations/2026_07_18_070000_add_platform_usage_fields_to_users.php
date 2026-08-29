<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
            $table->unsignedBigInteger('login_count')->default(0)->after('last_login_at');
            $table->softDeletes();
            $table->index(['role', 'is_active', 'created_at'], 'users_platform_usage_idx');
        });

        Schema::create('user_login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('logged_in_at')->useCurrent();
            $table->index(['logged_in_at', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_events');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_platform_usage_idx');
            $table->dropColumn(['last_login_at', 'login_count', 'deleted_at']);
        });
    }
};
