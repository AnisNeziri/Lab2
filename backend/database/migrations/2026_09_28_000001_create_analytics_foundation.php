<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('analytics_snapshots',function(Blueprint $t){
            $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->date('snapshot_date');
            $t->string('entity_type',24);$t->unsignedBigInteger('entity_id');$t->unsignedBigInteger('warehouse_id')->default(0);
            $t->string('feature_version',32);$t->timestamp('observed_at');$t->json('facts');$t->timestamps();
            $t->unique(['company_id','snapshot_date','entity_type','entity_id','warehouse_id'],'analytics_snapshot_identity');
            $t->index(['company_id','entity_type','snapshot_date']);
        });
        Schema::create('analytics_issues',function(Blueprint $t){
            $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->string('issue_key',64);$t->string('code',80);
            $t->string('severity',16);$t->string('entity_type',32);$t->unsignedBigInteger('entity_id');$t->json('details');
            $t->timestamp('detected_at');$t->timestamp('resolved_at')->nullable();$t->timestamps();$t->unique(['company_id','issue_key']);
        });
        Schema::create('analytics_datasets',function(Blueprint $t){
            $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->uuid('version')->unique();$t->string('name');
            $t->date('date_from');$t->date('date_to');$t->json('feature_definitions');$t->unsignedInteger('row_count')->default(0);
            $t->unsignedInteger('labelled_count')->default(0);$t->string('quality_status',32);$t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamps();
        });
        Schema::create('analytics_dataset_rows',function(Blueprint $t){
            $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->foreignId('analytics_dataset_id')->constrained()->cascadeOnDelete();
            $t->foreignId('analytics_snapshot_id')->constrained()->restrictOnDelete();$t->json('values');$t->unique(['analytics_dataset_id','analytics_snapshot_id'],'analytics_dataset_row_identity');
        });
        Schema::create('analytics_predictions',function(Blueprint $t){
            $t->id();$t->foreignId('company_id')->constrained()->cascadeOnDelete();$t->string('prediction_type');$t->string('entity_type',32);$t->unsignedBigInteger('entity_id');
            $t->string('model_key');$t->string('model_version');$t->string('input_feature_version');$t->foreignId('analytics_snapshot_id')->constrained()->restrictOnDelete();
            $t->json('value');$t->decimal('confidence',8,7)->nullable();$t->timestamp('generated_at');$t->timestamp('valid_until')->nullable();
            $t->json('actual_value')->nullable();$t->json('evaluation')->nullable();$t->timestamp('evaluated_at')->nullable();$t->timestamps();
            $t->index(['company_id','entity_type','entity_id']);
        });
    }
    public function down(): void {foreach(['analytics_predictions','analytics_dataset_rows','analytics_datasets','analytics_issues','analytics_snapshots'] as $table)Schema::dropIfExists($table);}
};
