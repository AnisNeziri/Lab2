<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_receipts', 'request_fingerprint')) {
                $table->char('request_fingerprint', 64)->nullable()->after('idempotency_key');
            }
        });

        Schema::table('inventory_returns', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_returns', 'request_fingerprint')) {
                $table->char('request_fingerprint', 64)->nullable()->after('idempotency_key');
            }
        });

        Schema::table('daily_sales', function (Blueprint $table): void {
            if (! Schema::hasColumn('daily_sales', 'idempotency_key')) {
                $table->uuid('idempotency_key')->nullable()->after('inventory_applied_at');
                $table->unique(['company_id', 'idempotency_key'], 'daily_sale_company_idempotency_uq');
            }
            if (! Schema::hasColumn('daily_sales', 'request_fingerprint')) {
                $table->char('request_fingerprint', 64)->nullable()->after('idempotency_key');
            }
            if (! Schema::hasColumn('daily_sales', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        if (! Schema::hasTable('inventory_expiry_alert_states')) {
            Schema::create('inventory_expiry_alert_states', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->cascadeOnDelete();
                $table->foreignId('notification_id')->nullable()->constrained()->nullOnDelete();
                $table->string('alert_state', 20)->nullable();
                $table->string('notified_state', 20)->nullable();
                $table->decimal('active_quantity', 18, 3)->default(0);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'inventory_lot_id'], 'expiry_alert_company_lot_uq');
                $table->index(['company_id', 'alert_state'], 'expiry_alert_company_state_idx');
            });
        }

        $rolePermissionTable = $this->rolePermissionTable();
        if (Schema::hasTable('permissions') && Schema::hasTable('roles') && $rolePermissionTable) {
            $permissionId = DB::table('permissions')->where('slug', 'inventory.expired.override')->value('id');
            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => 'Override Expired Inventory Block',
                    'slug' => 'inventory.expired.override',
                    'group' => 'inventory',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $roleIds = DB::table('roles')->whereIn('slug', ['superadmin', 'admin', 'manager'])->pluck('id');
            foreach ($roleIds as $roleId) {
                DB::table($rolePermissionTable)->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_expiry_alert_states');

        $rolePermissionTable = $this->rolePermissionTable();
        if (Schema::hasTable('permissions') && $rolePermissionTable) {
            $permissionId = DB::table('permissions')->where('slug', 'inventory.expired.override')->value('id');
            if ($permissionId) {
                DB::table($rolePermissionTable)->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        Schema::table('daily_sales', function (Blueprint $table): void {
            if (Schema::hasColumn('daily_sales', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
            if (Schema::hasColumn('daily_sales', 'request_fingerprint')) {
                $table->dropColumn('request_fingerprint');
            }
            if (Schema::hasColumn('daily_sales', 'idempotency_key')) {
                $table->dropUnique('daily_sale_company_idempotency_uq');
                $table->dropColumn('idempotency_key');
            }
        });

        Schema::table('inventory_returns', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_returns', 'request_fingerprint')) {
                $table->dropColumn('request_fingerprint');
            }
        });

        Schema::table('goods_receipts', function (Blueprint $table): void {
            if (Schema::hasColumn('goods_receipts', 'request_fingerprint')) {
                $table->dropColumn('request_fingerprint');
            }
        });
    }

    private function rolePermissionTable(): ?string
    {
        if (Schema::hasTable('role_permissions')) {
            return 'role_permissions';
        }

        return Schema::hasTable('role_permission') ? 'role_permission' : null;
    }
};
