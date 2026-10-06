<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('decision_learning_records',function(Blueprint $t){
   $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();
   $t->string('record_key',64);$t->string('kind',24);$t->string('domain',16);$t->string('decision_type',48);
   $t->string('source_type',48)->nullable();$t->unsignedBigInteger('source_id')->nullable();
   $t->uuid('version')->unique();$t->dateTime('evidence_cutoff')->nullable();$t->json('payload');
   $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamps();
   $t->unique(['company_id','record_key'],'learning_record_identity');
   $t->index(['company_id','kind','domain','id'],'learning_read_model');
   $t->index(['company_id','source_type','source_id'],'learning_source');
  });
 }
 public function down():void {Schema::dropIfExists('decision_learning_records');}
};
