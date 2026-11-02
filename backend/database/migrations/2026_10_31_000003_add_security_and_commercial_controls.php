<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        // Establish new key evidence only after an existing encrypted value can be unlocked.
        foreach (['webhook_endpoints'=>['secret'],'integration_providers'=>['credentials','configuration'],'employees'=>['bank_account','personal_number'],'fiscal_configurations'=>['credentials']] as $table=>$columns) {
            if (!Schema::hasTable($table)) continue;
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table,$column)) continue;
                $raw=DB::table($table)->whereNotNull($column)->where($column,'!=','')->value($column);
                if ($raw) try { Crypt::decryptString($raw); } catch (\Throwable) { throw new RuntimeException('The original application encryption key is required before this security upgrade. No schema changes were started.'); }
            }
        }
        // MariaDB DDL commits independently; each additive step is safely resumable.
        foreach (['public_id','locale','security_policy','role_permissions','capability_settings'] as $column) if (!Schema::hasColumn('companies',$column)) Schema::table('companies',function(Blueprint $t)use($column){
            if ($column==='public_id') $t->uuid($column)->nullable()->unique();
            elseif($column==='locale') $t->string($column,12)->default('en');
            else $t->json($column)->nullable();
        });
        $create=static function(string $name,callable $build):void { if(!Schema::hasTable($name)) Schema::create($name,$build); };
        DB::table('companies')->whereNull('public_id')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) DB::table('companies')->where('id', $row->id)->update(['public_id' => (string) Str::uuid()]);
        });
        $create('auth_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('company_id')->nullable()->constrained(); $t->foreignId('user_id')->constrained();
            $t->string('device', 160); $t->string('ip_address', 45)->nullable();
            $t->string('auth_method', 20)->default('password'); $t->dateTime('authenticated_at');
            $t->dateTime('last_active_at'); $t->dateTime('expires_at'); $t->dateTime('revoked_at')->nullable();
            $t->timestamps(); $t->index(['user_id', 'revoked_at', 'expires_at']);
        });
        if (!Schema::hasColumn('refresh_tokens','auth_session_id')) Schema::table('refresh_tokens', fn (Blueprint $t) => $t->uuid('auth_session_id')->nullable()->index());
        $create('passkey_credentials', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('user_id')->constrained();
            $t->string('credential_hash', 64)->unique(); $t->text('credential_id'); $t->text('public_key');
            $t->string('name', 80); $t->unsignedBigInteger('sign_count')->default(0);
            $t->json('transports')->nullable(); $t->boolean('backup_eligible')->default(false);
            $t->dateTime('last_used_at')->nullable(); $t->dateTime('revoked_at')->nullable(); $t->timestamps();
            $t->index(['user_id','revoked_at']);
        });
        $create('auth_challenges', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('user_id')->nullable()->constrained();
            $t->foreignId('company_id')->nullable()->constrained(); $t->uuid('auth_session_id')->nullable();
            $t->string('purpose', 24); $t->string('challenge', 128); $t->dateTime('expires_at');
            $t->dateTime('consumed_at')->nullable(); $t->timestamps(); $t->index('expires_at');
        });
        $create('security_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->nullable()->constrained(); $t->foreignId('user_id')->nullable()->constrained();
            $t->string('event', 80); $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('request_id', 100)->nullable(); $t->json('context')->nullable(); $t->dateTime('created_at');
            $t->index(['company_id', 'created_at']);
        });
        $create('installation_state', function (Blueprint $t) {
            $t->string('key', 80)->primary(); $t->text('value'); $t->dateTime('updated_at');
        });
        DB::table('installation_state')->insertOrIgnore([
            ['key'=>'identity','value'=>Crypt::encryptString((string) Str::uuid()),'updated_at'=>now()],
            ['key'=>'key_check','value'=>Crypt::encryptString('AIMS-ENCRYPTION-CHECK-V1'),'updated_at'=>now()],
        ]);
        $create('company_licenses', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->unique()->constrained(); $t->string('license_id', 100);
            $t->text('signed_document'); $t->string('document_hash',64); $t->dateTime('last_seen_at'); $t->timestamps();
        });
        $create('update_checks', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('user_id')->constrained();
            $t->string('target_version', 80); $t->string('manifest_hash',64); $t->string('stage',40);
            $t->json('checks'); $t->boolean('migration_started')->default(false); $t->boolean('code_changed')->default(false);
            $t->timestamps(); $t->index(['company_id','created_at']);
        });
        foreach (['security.view','security.manage','security.audit.export','licensing.manage','deployment.view','deployment.manage'] as $slug) {
            DB::table('permissions')->insertOrIgnore(['name'=>ucwords(str_replace('.',' ',$slug)),'slug'=>$slug,'group'=>'security','created_at'=>now(),'updated_at'=>now()]);
            $id=DB::table('permissions')->where('slug',$slug)->value('id');
            foreach (DB::table('roles')->whereIn('slug',['admin','superadmin'])->pluck('id') as $role) DB::table('role_permissions')->insertOrIgnore(['role_id'=>$role,'permission_id'=>$id]);
        }
        \App\Services\Security\RoleDefaults::install();
        \Illuminate\Support\Facades\Cache::forget('role_permissions:admin');
        \Illuminate\Support\Facades\Cache::forget('role_permissions:superadmin');
    }

    public function down(): void
    {
        throw new RuntimeException('Security history and licensed installation identity must be preserved. Restore a verified pre-upgrade backup into a separate installation.');
    }
};
