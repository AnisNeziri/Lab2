<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'preferences')) {
                $table->json('preferences')->nullable()->after('temporary_password_consumed');
            }
        });

        if (! Schema::hasTable('shipments')) {
            Schema::create('shipments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
                $table->string('vessel_name')->nullable();
                $table->string('mmsi', 20)->nullable();
                $table->string('tracking_reference')->nullable();
                $table->string('origin_port');
                $table->decimal('origin_lat', 10, 7);
                $table->decimal('origin_lng', 10, 7);
                $table->string('destination_port');
                $table->decimal('destination_lat', 10, 7);
                $table->decimal('destination_lng', 10, 7);
                $table->string('status')->default('pending');
                $table->timestamp('departed_at')->nullable();
                $table->timestamp('eta')->nullable();
                $table->decimal('current_lat', 10, 7)->nullable();
                $table->decimal('current_lng', 10, 7)->nullable();
                $table->string('last_location_label')->nullable();
                $table->unsignedInteger('distance_to_port_km')->nullable();
                $table->string('tracking_mode')->default('demo');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'preferences')) {
                $table->dropColumn('preferences');
            }
        });
    }
};
