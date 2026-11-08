<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('daily_sales_days',function(Blueprint $t){
   $t->timestamp('closed_at')->nullable();
   $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
   $t->unsignedInteger('closed_sale_count')->nullable();
   $t->unsignedBigInteger('closed_last_sale_id')->nullable();
  });
 }
 public function down():void {
  Schema::table('daily_sales_days',function(Blueprint $t){$t->dropForeign(['closed_by']);$t->dropColumn(['closed_at','closed_by','closed_sale_count','closed_last_sale_id']);});
 }
};
