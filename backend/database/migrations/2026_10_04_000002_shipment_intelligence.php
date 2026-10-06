<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('shipment_intelligence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $t->uuid('version')->unique();
            $t->boolean('is_current')->default(true);
            $t->string('fingerprint', 64);
            $t->string('risk', 32);
            $t->string('confidence', 24);
            $t->json('eta');
            $t->json('evidence');
            $t->json('impact');
            $t->json('alternatives');
            $t->json('outcome')->nullable();
            $t->dateTime('generated_at');
            $t->dateTime('evidence_cutoff');
            $t->dateTime('checked_at');
            $t->timestamps();
            $t->index(['company_id','is_current','risk'], 'ship_intel_attention');
            $t->index(['company_id','shipment_id','is_current'], 'ship_intel_current');
        });
    }
    public function down(): void { Schema::dropIfExists('shipment_intelligence'); }
};
