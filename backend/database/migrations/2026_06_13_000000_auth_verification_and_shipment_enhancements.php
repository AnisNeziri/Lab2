<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token');
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()->after('warehouse_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('shipments', 'tracking_provider')) {
                $table->string('tracking_provider')->default('demo')->after('tracking_mode');
            }
            if (! Schema::hasColumn('shipments', 'is_saved')) {
                $table->boolean('is_saved')->default(true)->after('tracking_provider');
            }
            if (! Schema::hasColumn('shipments', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('is_saved');
            }
            if (! Schema::hasColumn('shipments', 'risk_level')) {
                $table->string('risk_level')->default('on_schedule')->after('archived_at');
            }
            if (! Schema::hasColumn('shipments', 'previous_eta')) {
                $table->timestamp('previous_eta')->nullable()->after('eta');
            }
            if (! Schema::hasColumn('shipments', 'notification_state')) {
                $table->json('notification_state')->nullable()->after('risk_level');
            }
        });

        Schema::create('shipment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        if (Schema::hasTable('users')) {
            DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_histories');
        Schema::dropIfExists('email_verification_tokens');

        Schema::table('shipments', function (Blueprint $table) {
            $columns = [
                'supplier_id',
                'is_saved',
                'archived_at',
                'risk_level',
                'previous_eta',
                'tracking_provider',
                'notification_state',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('shipments', $column)) {
                    if ($column === 'supplier_id') {
                        $table->dropConstrainedForeignId('supplier_id');
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });
    }
};
