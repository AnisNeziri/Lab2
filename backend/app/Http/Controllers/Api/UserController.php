<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(
        private UserRepositoryInterface $users
    ) {}

    public function index(): JsonResponse
    {
        $this->ensureCompanyAdministrator();

        return response()->json(
            $this->users->allByCompany(Auth::user()->company_id)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureCompanyAdministrator();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['manager', 'staff'])],
            'temporary_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->users->create([
            'company_id' => $this->companyIdForLocalUsers(),
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['temporary_password'],
            'role' => $validated['role'],
            'must_change_password' => true,
            'temporary_password_consumed' => false,
            'email_verified_at' => config('system.operation_mode') === 'offline' ? now() : null,
        ]);

        if (config('system.operation_mode') === 'offline') {
            return response()->json([
                'message' => 'Local user created. They can sign in with the temporary password.',
                'user' => $this->formatUser($user),
            ], 201);
        }

        try {
            app(EmailVerificationService::class)->sendVerification($user);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json([
            'message' => 'User created. They must verify their email, then sign in with the temporary password.',
            'user' => $this->formatUser($user),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureCompanyAdministrator();
        $this->ensureManageableUser($user);

        $validated = $request->validate([
            'role' => ['required', Rule::in(['manager', 'staff'])],
        ]);

        return response()->json([
            'message' => 'User role updated.',
            'user' => $this->formatUser($this->users->update($user, $validated)),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $this->ensureCompanyAdministrator();
        $this->ensureManageableUser($user);

        $user->forceDelete();

        return response()->json(null, 204);
    }

    private function ensureManageableUser(User $user): void
    {
        $admin = Auth::user();

        if ($user->company_id !== $admin->company_id) {
            throw ValidationException::withMessages(['user' => ['This user does not belong to your company.']]);
        }

        if ($user->id === $admin->id) {
            throw ValidationException::withMessages(['user' => ['You cannot modify your own account here.']]);
        }

        if ($user->role === 'admin') {
            throw ValidationException::withMessages(['user' => ['Admin accounts cannot be modified from this screen.']]);
        }
    }

    private function ensureCompanyAdministrator(): void
    {
        $user = Auth::user();

        if ($user?->role === 'superadmin') {
            abort(403, 'Use the superadmin console to manage companies and users.');
        }

        if (config('system.operation_mode') === 'offline' && $user?->role !== 'admin') {
            abort(403, 'Only the local company administrator can manage users.');
        }
    }

    private function companyIdForLocalUsers(): int
    {
        $companyId = Auth::user()->company_id;

        if ($companyId) {
            return $companyId;
        }

        return Company::query()->value('id')
            ?? throw ValidationException::withMessages(['company' => ['Create a company before creating users.']]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'must_change_password' => $user->must_change_password,
            'created_at' => $user->created_at,
        ];
    }
}
