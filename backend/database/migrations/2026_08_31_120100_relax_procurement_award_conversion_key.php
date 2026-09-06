<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { $indexes=Schema::getIndexListing('procurement_awards'); if(in_array('procurement_award_conversion_unique',$indexes,true)){Schema::table('procurement_awards', fn ($table) => $table->index('company_id','procurement_award_company_idx'));Schema::table('procurement_awards', fn ($table) => $table->dropUnique('procurement_award_conversion_unique'));} if(!in_array('procurement_award_conversion_idx',Schema::getIndexListing('procurement_awards'),true))Schema::table('procurement_awards', fn ($table) => $table->index(['company_id','conversion_key'], 'procurement_award_conversion_idx')); } public function down(): void {} };
