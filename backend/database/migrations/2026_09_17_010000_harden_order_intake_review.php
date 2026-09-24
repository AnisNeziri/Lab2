<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void {
        Schema::table('order_intakes', function(Blueprint $t) {
            $t->json('received_payload')->nullable();
            $t->string('validation_signature',64)->nullable();
            $t->unsignedInteger('conflict_count')->default(0);
            $t->json('last_conflict')->nullable();
        });
        DB::table('order_intakes')->update(['received_payload'=>DB::raw('payload')]);
    }
    public function down(): void {
        Schema::table('order_intakes',fn(Blueprint $t)=>$t->dropColumn(['received_payload','validation_signature','conflict_count','last_conflict']));
    }
};
