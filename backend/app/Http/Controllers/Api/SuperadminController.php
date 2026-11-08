<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Security\SecurityEvents;
use App\Services\Security\SessionSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SuperadminController extends Controller
{
    public function dashboard(): JsonResponse
    {
        app(SecurityEvents::class)->record('platform.dashboard_viewed');
        $users = User::withoutGlobalScopes()->where('role', '!=', 'superadmin');
        $thirtyDaysAgo = now()->subDays(30);
        $loginEvents = DB::table('user_login_events')
            ->join('users', 'users.id', '=', 'user_login_events.user_id')
            ->whereNull('users.deleted_at')
            ->where('users.role', '!=', 'superadmin');

        return response()->json([
            'companies' => Company::count(),
            'total_users' => (clone $users)->count(),
            'active_users' => (clone $users)->where('is_active', true)->count(),
            'new_users_30_days' => (clone $users)->where('created_at', '>=', $thirtyDaysAgo)->count(),
            'active_users_30_days' => (clone $loginEvents)->where('logged_in_at', '>=', $thirtyDaysAgo)->distinct()->count('user_login_events.user_id'),
            'logins_today' => (clone $loginEvents)->whereDate('logged_in_at', today())->count(),
            'logins_30_days' => (clone $loginEvents)->where('logged_in_at', '>=', $thirtyDaysAgo)->count(),
            'total_logins' => (clone $loginEvents)->count(),
            'actions_30_days' => ActivityLog::withoutGlobalScopes()->where('created_at', '>=', $thirtyDaysAgo)->count(),
            'recent_users' => (clone $users)
                ->with('company:id,name')
                ->latest('created_at')
                ->limit(5)
                ->get(['id', 'company_id', 'name', 'email', 'created_at']),
        ]);
    }

    public function companies(): JsonResponse
    {
        app(SecurityEvents::class)->record('platform.company_directory_viewed');
        return response()->json(Company::query()
            ->withCount('users')
            ->orderBy('name')
            ->get(['id', 'name', 'address', 'created_at']));
    }

    public function storeCompany(Request $request): JsonResponse
    {
        app(SessionSecurity::class)->recent();
        if (config('system.operation_mode') === 'offline' && Company::query()->exists()) {
            throw ValidationException::withMessages(['company' => ['This local AIMS installation supports one company only.']]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:2000'],
        ]);

        $company = Company::create($data);
        app(SecurityEvents::class)->record('platform.company_created', subject: $company->id);

        return response()->json($company, 201);
    }

    public function users(): JsonResponse
    {
        app(SecurityEvents::class)->record('platform.user_directory_viewed');
        return response()->json(User::withoutGlobalScopes()
            ->with('company:id,name')
            ->orderByDesc('created_at')
            ->get(['id', 'company_id', 'name', 'email', 'role', 'is_active', 'must_change_password', 'last_login_at', 'login_count', 'created_at']));
    }

    public function storeUser(Request $request): JsonResponse
    {
        app(SessionSecurity::class)->recent();
        $offline = config('system.operation_mode') === 'offline';
        $localCompanyId = null;
        if ($offline) {
            // The desktop superadmin is global (company_id is null), while
            // the single company user must belong to the local company.
            abort_if(Company::query()->count() > 1, 403, 'Local user maintenance requires a single company.');
            $localCompanyId = Company::query()->value('id');
            if (! $localCompanyId) {
                throw ValidationException::withMessages([
                    'company_id' => ['Create the local company before creating its desktop user.'],
                ]);
            }

            if (User::withoutGlobalScopes()
                ->where('company_id', $localCompanyId)
                ->where('role', '!=', 'superadmin')
                ->whereNull('deleted_at')
                ->exists()) {
                throw ValidationException::withMessages([
                    'user' => ['This desktop installation already has its company user. Use password reset for that user.'],
                ]);
            }
        }

        $data = $request->validate([
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['admin', 'manager', 'staff'])],
            'temporary_password' => [...\App\Services\Security\PasswordPolicy::rules(), 'confirmed'],
        ]);

        if ($offline) {
            $data['company_id'] = $localCompanyId;
        }

        $user = DB::transaction(function () use ($data, $offline) {
            Company::query()->lockForUpdate()->findOrFail($data['company_id']);
            // Only initial provisioning; managed companies own subsequent user administration.
            abort_if(User::query()->when(!$offline, fn ($users) => $users->withTrashed())->where('company_id', $data['company_id'])->exists()
                || (!$offline && $data['role'] !== 'admin'), 403, 'Platform provisioning is limited to the first company administrator.');
            $user = User::create([
                'company_id' => $data['company_id'],
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['temporary_password']),
                'role' => $data['role'],
                'is_active' => true,
                'email_verified_at' => now(),
                'must_change_password' => true,
                'temporary_password_consumed' => false,
            ]);

            $role = Role::where('slug', $user->role)->first();
            if ($role) {
                UserRole::updateOrCreate(['user_id' => $user->id, 'role_id' => $role->id], ['assigned_at' => now()]);
            }

            app(SecurityEvents::class)->record('platform.initial_user_provisioned', context: ['role' => $user->role], subject: $user->id);
            return $user;
        });
        return response()->json($this->formatUser($user->load('company:id,name')), 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeLocalUserMaintenance($user);
        if ($user->role === 'superadmin') {
            throw ValidationException::withMessages(['user' => ['Superadmin accounts cannot be changed here.']]);
        }

        $data = $request->validate([
            'company_id' => ['sometimes', 'integer', Rule::exists('companies', 'id')],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(['admin', 'manager', 'staff'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user->update($data);
        app(SecurityEvents::class)->record('platform.local_user_updated', subject: $user->id);

        if (isset($data['role']) && ($role = Role::where('slug', $data['role'])->first())) {
            UserRole::where('user_id', $user->id)->delete();
            UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'assigned_at' => now()]);
        }

        return response()->json($this->formatUser($user->fresh()->load('company:id,name')));
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorizeLocalUserMaintenance($user);
        if ($user->role === 'superadmin') {
            throw ValidationException::withMessages(['user' => ['Superadmin passwords cannot be reset here.']]);
        }

        $data = $request->validate([
            'temporary_password' => [...\App\Services\Security\PasswordPolicy::rules(), 'confirmed'],
        ]);

        DB::transaction(function () use ($data, $user) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->forceFill([
                'password' => Hash::make($data['temporary_password']),
                'must_change_password' => true,
                'temporary_password_consumed' => false,
                'api_token' => null,
                'api_token_expires_at' => null,
                'token_version' => (int) $locked->token_version + 1,
            ])->save();
            app(SessionSecurity::class)->revoke($locked);
            app(SecurityEvents::class)->record('platform.local_user_password_reset', subject: $locked->id);
        });

        return response()->json(['message' => 'Temporary password updated.']);
    }

    public function destroyUser(User $user): JsonResponse
    {
        $this->authorizeLocalUserMaintenance($user);
        if ($user->role === 'superadmin') {
            throw ValidationException::withMessages(['user' => ['Superadmin accounts cannot be deleted.']]);
        }

        DB::transaction(fn () => $user->delete());
        app(SecurityEvents::class)->record('platform.local_user_removed', subject: $user->id);

        return response()->json(['message' => 'User removed.']);
    }

    private function authorizeLocalUserMaintenance(User $user): void
    {
        abort_unless(config('system.operation_mode') === 'offline' && $user->company_id
            && Company::query()->count() === 1 && Company::query()->whereKey($user->company_id)->exists(),
            403, 'Existing company accounts are administered by the company.');
        app(SessionSecurity::class)->recent();
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'company_id' => $user->company_id,
            'company' => $user->company ? ['id' => $user->company->id, 'name' => $user->company->name] : null,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'must_change_password' => $user->must_change_password,
            'last_login_at' => $user->last_login_at,
            'login_count' => $user->login_count,
            'created_at' => $user->created_at,
        ];
    }
}
