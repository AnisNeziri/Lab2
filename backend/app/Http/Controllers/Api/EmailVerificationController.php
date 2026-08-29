<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'size:6'],
        ]);

        if (empty($validated['token']) && empty($validated['code'])) {
            return response()->json([
                'message' => 'A verification token or code is required.',
                'errors' => ['code' => ['Enter the 6-digit code or use the email link.']],
            ], 422);
        }

        $user = $this->verification->verify(
            $validated['email'],
            $validated['token'] ?? null,
            $validated['code'] ?? null,
        );

        if (! $user) {
            return response()->json([
                'message' => 'Verification link or code is invalid or expired.',
                'errors' => ['code' => ['Verification failed.']],
            ], 422);
        }

        return response()->json([
            'message' => 'Email verified successfully. You can now sign in.',
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $this->verification->resend($validated['email']);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }

        return response()->json([
            'message' => 'If that account exists and is unverified, a new verification email has been sent from AIMS.',
        ]);
    }
}
