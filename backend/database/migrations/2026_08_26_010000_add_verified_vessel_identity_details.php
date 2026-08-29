<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('origin_port')->nullable()->change();
            $table->string('destination_port')->nullable()->change();

            if (! Schema::hasColumn('shipments', 'imo')) {
                $table->string('imo', 20)->nullable()->after('mmsi');
            }
            if (! Schema::hasColumn('shipments', 'call_sign')) {
                $table->string('call_sign', 50)->nullable()->after('imo');
            }
            if (! Schema::hasColumn('shipments', 'vessel_type')) {
                $table->string('vessel_type', 100)->nullable()->after('call_sign');
            }
            if (! Schema::hasColumn('shipments', 'flag_country')) {
                $table->string('flag_country', 100)->nullable()->after('vessel_type');
            }
            if (! Schema::hasColumn('shipments', 'year_built')) {
                $table->unsignedSmallInteger('year_built')->nullable()->after('flag_country');
            }
            if (! Schema::hasColumn('shipments', 'vessel_details')) {
                $table->json('vessel_details')->nullable()->after('year_built');
            }
            if (! Schema::hasColumn('shipments', 'details_provider')) {
                $table->string('details_provider', 50)->nullable()->after('vessel_details');
            }
            if (! Schema::hasColumn('shipments', 'details_updated_at')) {
                $table->timestamp('details_updated_at')->nullable()->after('details_provider');
            }
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['company_id', 'mmsi'], 'shipments_company_mmsi_index');
            $table->index(['company_id', 'imo'], 'shipments_company_imo_index');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('shipments_company_mmsi_index');
            $table->dropIndex('shipments_company_imo_index');
            $table->dropColumn([
                'imo', 'call_sign', 'vessel_type', 'flag_country', 'year_built',
                'vessel_details', 'details_provider', 'details_updated_at',
            ]);
        });
    }
};
