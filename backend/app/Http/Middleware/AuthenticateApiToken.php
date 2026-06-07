<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function __construct(
        private JwtService $jwt
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);

            if ($token) {
                $payload = $this->jwt->validateAccessToken($token);

                if ($payload) {
                    $user = User::find($payload->sub ?? null);

                    if ($user && $user->is_active !== false) {
                        Auth::login($user);

                        return $next($request);
                    }
                }

                $user = User::where('api_token', hash('sha256', $token))->first();

                if ($user && $user->is_active !== false) {
                    Auth::login($user);

                    return $next($request);
                }
            }
        }

        return response()->json([
            'message' => 'Unauthorized. Please login again.',
        ], 401);
    }
}
