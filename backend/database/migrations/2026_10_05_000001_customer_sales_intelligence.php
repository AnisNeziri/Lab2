<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('customer_sales_snapshots',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->string('fingerprint',64);$t->uuid('version');$t->date('as_of');$t->timestamp('evidence_cutoff');$t->json('evidence');$t->timestamps();$t->unique(['company_id','fingerprint']);});
  Schema::create('customer_intelligence_policies',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->uuid('version');$t->json('settings');$t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamps();});
 }
 public function down():void {Schema::dropIfExists('customer_intelligence_policies');Schema::dropIfExists('customer_sales_snapshots');}
};
