<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
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
        return response()->json(
            $this->users->allByCompany(Auth::user()->company_id)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                           => ['required', 'string', 'max:255'],
            'email'                          => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role'                           => ['required', Rule::in(['manager', 'staff'])],
            'temporary_password'             => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->users->create([
            'company_id'                  => Auth::user()->company_id,
            'name'                        => $validated['name'],
            'email'                       => $validated['email'],
            'password'                    => $validated['temporary_password'],
            'role'                        => $validated['role'],
            'must_change_password'        => true,
            'temporary_password_consumed' => false,
        ]);

        return response()->json([
            'message' => 'User created. They must set a personal password on first login.',
            'user'    => $this->formatUser($user),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureManageableUser($user);

        $validated = $request->validate([
            'role' => ['required', Rule::in(['manager', 'staff'])],
        ]);

        return response()->json([
            'message' => 'User role updated.',
            'user'    => $this->formatUser($this->users->update($user, $validated)),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $this->ensureManageableUser($user);

        $this->users->delete($user);

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

    private function formatUser(User $user): array
    {
        return [
            'id'                   => $user->id,
            'name'                 => $user->name,
            'email'                => $user->email,
            'role'                 => $user->role,
            'must_change_password' => $user->must_change_password,
            'created_at'           => $user->created_at,
        ];
    }
}
