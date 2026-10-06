<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  foreach(['inventory_forecast_models','inventory_model_decisions'] as $table)Schema::table($table,function(Blueprint $t){
   $t->unsignedBigInteger('product_id')->nullable()->change();
   $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
   $t->string('domain',32)->default('inventory_demand');
  });
  Schema::create('supplier_delivery_risks',function(Blueprint $t){
   $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->foreignId('supplier_id')->constrained()->restrictOnDelete();
   $t->foreignId('purchase_order_id')->constrained()->restrictOnDelete();$t->string('risk',20);$t->unsignedInteger('episode')->default(0);$t->json('evidence');$t->json('feedback')->nullable();$t->timestamps();
   $t->unique(['company_id','purchase_order_id'],'supplier_risk_po');
  });
 }
 public function down():void {Schema::dropIfExists('supplier_delivery_risks');foreach(['inventory_model_decisions','inventory_forecast_models'] as $table)Schema::table($table,function(Blueprint $t){$t->dropForeign(['supplier_id']);$t->dropColumn(['supplier_id','domain']);});}
};
