<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('outbound_returns', function (Blueprint $table) {
            $table->foreignId('outbound_package_item_id')->nullable()->constrained('outbound_package_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outbound_returns', fn (Blueprint $table) => $table->dropConstrainedForeignId('outbound_package_item_id'));
    }
};
