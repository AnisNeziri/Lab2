<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::table('inventory_forecast_models',fn(Blueprint $t)=>$t->json('review')->nullable());
        Schema::table('inventory_recommendations',function(Blueprint $t){$t->json('outcome')->nullable();$t->timestamp('outcome_updated_at')->nullable();});
        Schema::create('inventory_model_decisions',function(Blueprint $t){
            $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('from_model_id')->nullable()->constrained('inventory_forecast_models')->restrictOnDelete();
            $t->foreignId('to_model_id')->constrained('inventory_forecast_models')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->unsignedSmallInteger('horizon');
            $t->uuid('decision_key');$t->unique(['company_id','decision_key'],'model_decision_identity');
            $t->string('action',20);$t->text('reason');$t->json('evidence');$t->timestamps();
            $t->index(['company_id','product_id','horizon'],'model_decision_lookup');
        });
        Schema::create('inventory_intelligence_alerts',function(Blueprint $t){
            $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('horizon')->default(0);$t->string('code',40);$t->string('status',20)->default('open');
            $t->unsignedInteger('episode')->default(1);$t->json('evidence');$t->timestamp('opened_at');$t->timestamp('resolved_at')->nullable();$t->timestamps();
            $t->unique(['company_id','product_id','horizon','code'],'intelligence_alert_identity');
        });
    }
    public function down():void {Schema::dropIfExists('inventory_intelligence_alerts');Schema::dropIfExists('inventory_model_decisions');Schema::table('inventory_recommendations',fn(Blueprint $t)=>$t->dropColumn(['outcome','outcome_updated_at']));Schema::table('inventory_forecast_models',fn(Blueprint $t)=>$t->dropColumn('review'));}
};
