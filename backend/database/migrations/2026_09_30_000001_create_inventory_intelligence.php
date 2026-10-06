<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_forecast_models',function(Blueprint $t){
            $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('analytics_dataset_id')->constrained()->restrictOnDelete();$t->uuid('version');$t->string('feature_version');
            $t->unsignedSmallInteger('horizon');$t->string('algorithm',32);$t->string('status',20);$t->date('training_cutoff');
            $t->json('artifact');$t->string('artifact_hash',64);$t->json('metrics')->nullable();$t->json('comparison');$t->json('quality');$t->timestamps();
            $t->unique(['company_id','version']);$t->index(['company_id','product_id','horizon','status'],'forecast_model_lookup');
        });
        Schema::create('inventory_recommendations',function(Blueprint $t){
            $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('analytics_prediction_id')->constrained()->restrictOnDelete();$t->foreignId('purchase_request_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status',20)->default('open');$t->string('risk',24);$t->json('explanation');$t->json('feedback')->nullable();$t->timestamp('viewed_at')->nullable();$t->timestamps();
            $t->unique('analytics_prediction_id');$t->index(['company_id','product_id','status'],'recommendation_lookup');
        });
    }
    public function down():void {Schema::dropIfExists('inventory_recommendations');Schema::dropIfExists('inventory_forecast_models');}
};
