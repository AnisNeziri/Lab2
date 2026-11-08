<?php
// Add only harness bookkeeping to the owned disposable schema. No business migration.
$argv[1]='migrate';
require __DIR__.'/bootstrap.php';
if (!Illuminate\Support\Facades\Schema::hasTable('_dc16_checkpoints')) {
    Illuminate\Support\Facades\Schema::create('_dc16_checkpoints',function(Illuminate\Database\Schema\Blueprint $table){
        $table->string('name')->primary(); $table->integer('completed_day')->default(-1); $table->longText('state'); $table->timestamp('saved_at');
    });
}
echo "Isolated harness checkpoint table ready.\n";
