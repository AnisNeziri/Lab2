<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('enterprise_decisions',function(Blueprint $t){
  $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->foreignId('product_id')->constrained()->cascadeOnDelete();$t->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
  $t->string('logical_key',64);$t->uuid('version')->unique();$t->string('decision_type',40);$t->string('severity',12);$t->string('confidence',12);$t->string('status',16)->default('open');
  $t->string('source_fingerprint',64);$t->json('evidence');$t->json('alternatives');$t->json('reasoning');$t->json('history');$t->json('outcome')->nullable();
  $t->foreignId('purchase_request_id')->nullable()->constrained()->nullOnDelete();$t->foreignId('stock_transfer_id')->nullable()->constrained()->nullOnDelete();
  // DATETIME avoids legacy MariaDB's implicit zero defaults on the second
  // required TIMESTAMP, while retaining explicit application-owned dates.
  $t->dateTime('generated_at');$t->dateTime('evidence_cutoff_at');$t->dateTime('last_checked_at');$t->timestamps();
  $t->index(['company_id','logical_key','id'],'decision_identity');$t->index(['company_id','status','severity'],'decision_attention');$t->index(['company_id','product_id','warehouse_id'],'decision_product_scope');
 });}
 public function down():void {Schema::dropIfExists('enterprise_decisions');}
};
