<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Keeping the image with the product makes the feature work in the
            // offline desktop build as well as the hosted web application.
            $table->longText('image_data')->nullable()->after('description');
            $table->string('image_mime', 100)->nullable()->after('image_data');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['image_data', 'image_mime']);
        });
    }
};
