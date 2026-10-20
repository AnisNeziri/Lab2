<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->string('fingerprint', 64);
            $table->json('result');
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
        });
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            // Optional: historical PO receipts cannot be attributed to cargo retrospectively.
            $table->foreignId('shipment_item_id')->nullable()->constrained('shipment_items')->restrictOnDelete();
            $table->decimal('shipment_received_base_quantity', 18, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservation_runs');
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipment_item_id');
            $table->dropColumn('shipment_received_base_quantity');
        });
    }
};
