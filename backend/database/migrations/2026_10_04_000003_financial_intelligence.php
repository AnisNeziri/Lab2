<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('financial_intelligence_snapshots',function(Blueprint $t){
   $t->id();$t->foreignId('company_id')->constrained();$t->string('fingerprint',64);$t->string('version',40);
   $t->date('as_of');$t->timestamp('evidence_cutoff');$t->json('evidence');$t->json('forecast');$t->json('evaluation')->nullable();$t->timestamps();
   $t->unique(['company_id','fingerprint'],'fi_snapshot_fingerprint');$t->index(['company_id','as_of']);
  });
  Schema::create('financial_intelligence_observations',function(Blueprint $t){
   $t->id();$t->foreignId('company_id')->constrained();$t->date('observation_date');$t->timestamp('observed_at');$t->json('facts');$t->timestamps();
   $t->unique(['company_id','observation_date'],'fi_observation_date');
  });
  Schema::create('financial_intelligence_policies',function(Blueprint $t){
   $t->id();$t->foreignId('company_id')->constrained();$t->string('version',40);$t->json('settings');$t->foreignId('created_by')->nullable()->constrained('users');$t->timestamps();
  });
 }
 public function down():void {foreach(['financial_intelligence_policies','financial_intelligence_observations','financial_intelligence_snapshots'] as $t)Schema::dropIfExists($t);}
};
