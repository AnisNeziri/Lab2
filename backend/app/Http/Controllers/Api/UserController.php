<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->where('company_id', Auth::user()->company_id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'must_change_password', 'created_at']);

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'role' => ['required', Rule::in(['manager', 'staff'])],
            'temporary_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'company_id' => Auth::user()->company_id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['temporary_password'],
            'role' => $validated['role'],
            'must_change_password' => true,
            'temporary_password_consumed' => false,
        ]);

        return response()->json([
            'message' => 'User created. They must set a personal password on first login.',
            'user' => $this->formatUser($user),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureManageableUser($user);

        $validated = $request->validate([
            'role' => ['required', Rule::in(['manager', 'staff'])],
        ]);

        $user->update(['role' => $validated['role']]);

        return response()->json([
            'message' => 'User role updated.',
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $this->ensureManageableUser($user);

        $user->delete();

        return response()->json(null, 204);
    }

    private function ensureManageableUser(User $user): void
    {
        $admin = Auth::user();

        if ($user->company_id !== $admin->company_id) {
            throw ValidationException::withMessages([
                'user' => ['This user does not belong to your company.'],
            ]);
        }

        if ($user->id === $admin->id) {
            throw ValidationException::withMessages([
                'user' => ['You cannot modify your own account here.'],
            ]);
        }

        if ($user->role === 'admin') {
            throw ValidationException::withMessages([
                'user' => ['Admin accounts cannot be modified from this screen.'],
            ]);
        }
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
