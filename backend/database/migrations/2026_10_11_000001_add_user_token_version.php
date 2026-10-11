<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('token_version')->default(0));
    }

    public function down(): void
    {
        // Revocation history must survive an application rollback. Restore a
        // verified pre-release database if the old application requires it.
    }
};
