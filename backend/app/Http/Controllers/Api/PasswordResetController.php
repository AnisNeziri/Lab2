<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $passwordReset) {}

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $this->passwordReset->sendResetLink($validated['email']);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }

        return response()->json([
            'message' => 'If that email exists, a password reset email has been sent from AIMS.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->passwordReset->reset(
            $validated['email'],
            $validated['token'],
            $validated['password'],
        );

        if (! $user) {
            return response()->json([
                'message' => 'Password reset token is invalid or expired.',
                'errors' => ['token' => ['Reset link expired. Request a new one.']],
            ], 422);
        }

        return response()->json([
            'message' => 'Password reset successfully. You can now sign in.',
        ]);
    }
}
