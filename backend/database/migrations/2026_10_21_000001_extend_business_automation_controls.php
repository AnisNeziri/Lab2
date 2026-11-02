<?php

use App\Services\AutomationPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->json('automation_settings')->nullable());
        Schema::table('automations', function (Blueprint $t) {
            $t->string('lifecycle_state')->default('draft');
            $t->unsignedBigInteger('scan_cursor')->default(0);
        });
        DB::table('automations')->where('enabled', true)->update(['lifecycle_state' => 'active']);
        Schema::table('operational_tasks', function (Blueprint $t) {
            $t->string('assigned_team')->nullable();
            $t->index(['company_id', 'assigned_team', 'status'], 'task_team_queue_idx');
        });
        Schema::table('automation_executions', function (Blueprint $t) {
            $t->json('trigger_context')->nullable();
            $t->string('failure_class')->nullable();
        });
        AutomationPermissions::install(true);
    }

    public function down(): void
    {
        Schema::table('automation_executions', fn (Blueprint $t) => $t->dropColumn(['trigger_context', 'failure_class']));
        Schema::table('operational_tasks', function (Blueprint $t) {
            $t->dropIndex('task_team_queue_idx');
            $t->dropColumn('assigned_team');
        });
        Schema::table('automations', fn (Blueprint $t) => $t->dropColumn(['lifecycle_state', 'scan_cursor']));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('automation_settings'));
    }
};
