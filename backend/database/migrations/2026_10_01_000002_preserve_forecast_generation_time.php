<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Older MySQL configurations implicitly add ON UPDATE to the first
        // TIMESTAMP. Evaluating a forecast must not rewrite its origin time.
        Schema::table('analytics_predictions', function (Blueprint $table) {
            $table->timestamp('generated_at')->useCurrent()->change();
        });
    }

    public function down(): void
    {
        // Deliberately retain the safe timestamp semantics on rollback.
    }
};
