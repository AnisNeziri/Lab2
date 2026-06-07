<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->timestamp('assigned_at')->useCurrent();
                $table->unique(['user_id', 'role_id']);
            });
        }

        if (! Schema::hasTable('refresh_tokens')) {
            Schema::create('refresh_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('token_hash');
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'expires_at']);
            });
        }

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('files')) {
            Schema::create('files', function (Blueprint $table) {
                $table->id();
                $table->string('entity');
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('filename');
                $table->string('file_path');
                $table->unsignedBigInteger('file_size')->default(0);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['entity', 'entity_id']);
            });
        }

        if (Schema::hasTable('activity_logs') && ! Schema::hasTable('audit_logs')) {
            Schema::rename('activity_logs', 'audit_logs');
        }

        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('audit_logs', 'entity')) {
                    $table->string('entity')->nullable()->after('action');
                }
                if (! Schema::hasColumn('audit_logs', 'entity_id')) {
                    $table->unsignedBigInteger('entity_id')->nullable()->after('entity');
                }
                if (! Schema::hasColumn('audit_logs', 'old_value')) {
                    $table->json('old_value')->nullable()->after('description');
                }
                if (! Schema::hasColumn('audit_logs', 'new_value')) {
                    $table->json('new_value')->nullable()->after('old_value');
                }
                if (! Schema::hasColumn('audit_logs', 'ip_address')) {
                    $table->string('ip_address', 45)->nullable()->after('new_value');
                }
                if (! Schema::hasColumn('audit_logs', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('role_permission') && ! Schema::hasTable('role_permissions')) {
            Schema::rename('role_permission', 'role_permissions');
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'first_name')) {
                    $table->string('first_name')->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('users', 'last_name')) {
                    $table->string('last_name')->nullable()->after('first_name');
                }
                if (! Schema::hasColumn('users', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('role');
                }
            });

            $users = DB::table('users')->select('id', 'name', 'role')->get();
            $roleMap = DB::table('roles')->pluck('id', 'slug');

            foreach ($users as $user) {
                $parts = explode(' ', trim($user->name), 2);
                DB::table('users')->where('id', $user->id)->update([
                    'first_name' => $parts[0] ?? $user->name,
                    'last_name' => $parts[1] ?? '',
                ]);

                if (isset($roleMap[$user->role]) && Schema::hasTable('user_roles')) {
                    DB::table('user_roles')->updateOrInsert(
                        ['user_id' => $user->id, 'role_id' => $roleMap[$user->role]],
                        ['assigned_at' => now()]
                    );
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('role_permissions') && ! Schema::hasTable('role_permission')) {
            Schema::rename('role_permissions', 'role_permission');
        }

        if (Schema::hasTable('audit_logs') && ! Schema::hasTable('activity_logs')) {
            Schema::rename('audit_logs', 'activity_logs');
        }

        Schema::dropIfExists('files');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('user_roles');

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'first_name')) {
                    $table->dropColumn('first_name');
                }
                if (Schema::hasColumn('users', 'last_name')) {
                    $table->dropColumn('last_name');
                }
                if (Schema::hasColumn('users', 'is_active')) {
                    $table->dropColumn('is_active');
                }
            });
        }
    }
};
