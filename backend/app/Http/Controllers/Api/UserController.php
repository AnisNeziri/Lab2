<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
        ]);

        $temporaryPassword = Str::password(12);

        $user = User::create([
            'company_id' => Auth::user()->company_id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $temporaryPassword,
            'role' => $validated['role'],
            'must_change_password' => true,
            'temporary_password_consumed' => false,
        ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'must_change_password' => $user->must_change_password,
                'created_at' => $user->created_at,
            ],
            'temporary_password' => $temporaryPassword,
        ], 201);
    }
}
