<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('inventory_planning_policies',function(Blueprint $t){
   $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->foreignId('product_id')->constrained()->cascadeOnDelete();
   $t->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();$t->unsignedBigInteger('scope_key')->default(0);
   $t->uuid('version')->unique();$t->json('settings');$t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamps();
   $t->index(['company_id','product_id','scope_key'],'planning_policy_scope');
  });
  Schema::table('inventory_recommendations',function(Blueprint $t){
   $t->foreignId('planning_policy_id')->nullable()->constrained('inventory_planning_policies')->nullOnDelete();
   $t->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();$t->string('planning_key',64)->nullable()->unique();
  });
 }
 public function down():void {Schema::table('inventory_recommendations',function(Blueprint $t){$t->dropConstrainedForeignId('planning_policy_id');$t->dropConstrainedForeignId('warehouse_id');$t->dropColumn('planning_key');});Schema::dropIfExists('inventory_planning_policies');}
};
