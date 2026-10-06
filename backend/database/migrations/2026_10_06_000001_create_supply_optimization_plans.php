<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('supply_optimization_plans',function(Blueprint $t){
  $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->uuid('version')->unique();
  $t->string('status',24)->default('QUEUED');$t->string('request_key',64);$t->json('scope');$t->json('input')->nullable();$t->json('result')->nullable();
  $t->json('approved')->nullable();$t->json('drafts')->nullable();$t->json('execution')->nullable();$t->text('error')->nullable();
  $t->timestamp('evidence_cutoff')->nullable();$t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamps();
  $t->index(['company_id','status']);$t->index(['company_id','request_key']);
 });}
 public function down():void {Schema::dropIfExists('supply_optimization_plans');}
};
