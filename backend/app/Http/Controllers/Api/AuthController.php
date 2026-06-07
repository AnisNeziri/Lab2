<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->authService->attemptLogin($validated['email'], $validated['password']);

        if (($result['error'] ?? null) === 'not_found') {
            return response()->json([
                'message' => 'User not found.',
                'errors' => ['email' => ['The provided credentials are incorrect.']],
            ], 422);
        }

        if (($result['error'] ?? null) === 'temp_expired') {
            return response()->json([
                'message' => 'Your temporary password has already been used. Contact your company administrator for assistance.',
                'errors' => ['email' => ['Temporary password expired.']],
            ], 422);
        }

        if (($result['error'] ?? null) === 'invalid_password') {
            return response()->json([
                'message' => 'Password incorrect.',
                'errors' => ['email' => ['The provided credentials are incorrect.']],
            ], 422);
        }

        return response()->json($result);
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $result = $this->authService->refresh($validated['refresh_token']);

        if (! $result) {
            return response()->json([
                'message' => 'Refresh token is invalid or expired.',
            ], 401);
        }

        return response()->json($result);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user) {
            $this->authService->logout($user);
        }

        return response()->json(['message' => 'Successfully logged out.']);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_address' => ['required', 'string', 'max:1000'],
        ]);

        $result = $this->authService->register($validated);

        return response()->json($result, 201);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user->must_change_password) {
            $validated = $request->validate([
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);
        } else {
            $validated = $request->validate([
                'current_password' => ['required', 'string'],
                'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
            ]);

            if (! Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'message' => 'Current password is incorrect.',
                    'errors' => ['current_password' => ['The current password is incorrect.']],
                ], 422);
            }
        }

        $user = $this->authService->changePassword($user, $validated);

        return response()->json([
            'message' => 'Password updated successfully.',
            'user' => $this->authService->formatUser($user),
        ]);
    }
}
